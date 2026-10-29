// SPDX-License-Identifier: GPL-2.0-or-later
/*
 * DHT11/DHT22 bit banging GPIO driver
 *
 * Copyright (c) Harald Geyer <harald@ccbib.org>
 */

#include <linux/err.h>
#include <linux/interrupt.h>
#include <linux/device.h>
#include <linux/kernel.h>
#include <linux/printk.h>
#include <linux/slab.h>
#include <linux/sysfs.h>
#include <linux/io.h>
#include <linux/mod_devicetable.h>
#include <linux/module.h>
#include <linux/jiffies.h>
#include <linux/platform_device.h>
#include <linux/wait.h>
#include <linux/bitops.h>
#include <linux/completion.h>
#include <linux/mutex.h>
#include <linux/delay.h>
#include <linux/gpio/consumer.h>
#include <linux/timekeeping.h>

#include <linux/iio/iio.h>
#include <linux/iio/sysfs.h>
#include "dht11_start.h"
#include "dht11_frame.h"
#include "dht11_counters.h"

#define DHT11_DATA_VALID_TIME	2000000000  /* 2s in ns */

#define DHT11_EDGES_PREAMBLE 2
#define DHT11_BITS_PER_READ 40
/*
 * Note that when reading the sensor actually 84 edges are detected, but
 * since the last edge is not significant, we only store 83:
 */
#define DHT11_EDGES_PER_READ (2 * DHT11_BITS_PER_READ + \
			      DHT11_EDGES_PREAMBLE + 1)

/*
 * Data transmission timing:
 * Data bits are encoded as pulse length (high time) on the data line.
 * 0-bit: 22-30uS -- typically 26uS (AM2302)
 * 1-bit: 68-75uS -- typically 70uS (AM2302)
 * The acutal timings also depend on the properties of the cable, with
 * longer cables typically making pulses shorter.
 *
 * Our decoding depends on the time resolution of the system:
 * timeres > 34uS ... don't know what a 1-tick pulse is
 * 34uS > timeres > 30uS ... no problem (30kHz and 32kHz clocks)
 * 30uS > timeres > 23uS ... don't know what a 2-tick pulse is
 * timeres < 23uS ... no problem
 *
 * Luckily clocks in the 33-44kHz range are quite uncommon, so we can
 * support most systems if the threshold for decoding a pulse as 1-bit
 * is chosen carefully. If somebody really wants to support clocks around
 * 40kHz, where this driver is most unreliable, there are two options.
 * a) select an implementation using busy loop polling on those systems
 * b) use the checksum to do some probabilistic decoding
 */
#define DHT11_START_TRANSMISSION_MIN	18000  /* us */
#define DHT11_START_TRANSMISSION_MAX	20000  /* us */
#define DHT11_MIN_TIMERES	34000  /* ns */
#define DHT11_THRESHOLD		49000  /* ns */
#define DHT11_AMBIG_LOW		23000  /* ns */
#define DHT11_AMBIG_HIGH	30000  /* ns */

struct dht11 {
	struct device			*dev;

	struct gpio_desc		*gpiod;
	int				irq;

	struct completion		completion;
	/* The iio sysfs interface doesn't prevent concurrent reads: */
	struct mutex			lock;
	struct dht11_counters		counters;
	enum dht11_decode_failure	decode_failure;

	s64				timestamp;
	int				temperature;
	int				humidity;

	/* num_edges: -1 means "no transmission in progress" */
	int				num_edges;
	struct dht11_edge		edges[DHT11_EDGES_PER_READ];
};

/* Camera-RPi experimental build: bounded adaptive decoding is defined below;
 * the IRQ path separately filters the observed short initial release pulse.
 * Extra printing occurs outside the edge IRQ, and expires automatically.
 */
static unsigned long diag_deadline;

static bool dht11_diag_active(void)
{
	unsigned long deadline = READ_ONCE(diag_deadline);

	return deadline && time_before(jiffies, deadline);
}

static int dht11_diag_set(const char *val, const struct kernel_param *kp)
{
	unsigned int seconds;
	int ret = kstrtouint(val, 0, &seconds);

	if (ret)
		return ret;
	if (seconds > 180)
		return -EINVAL;
	WRITE_ONCE(diag_deadline, seconds ? jiffies + seconds * HZ : 0);
	return 0;
}

static int dht11_diag_get(char *buffer, const struct kernel_param *kp)
{
	unsigned long deadline = READ_ONCE(diag_deadline);
	unsigned long now = jiffies;
	unsigned long left = deadline && time_before(now, deadline)
		? DIV_ROUND_UP(deadline - now, HZ) : 0;

	return scnprintf(buffer, PAGE_SIZE, "%lu\n", left);
}

static const struct kernel_param_ops dht11_diag_ops = {
	.set = dht11_diag_set,
	.get = dht11_diag_get,
};
module_param_cb(diag_seconds, &dht11_diag_ops, NULL, 0600);
MODULE_PARM_DESC(diag_seconds, "Temporary DHT timing diagnostics, 0..180 seconds");

/*
 * dht11_edges_print: show the data as actually received by the
 *                    driver.
 */
static void dht11_edges_print(struct dht11 *dht11)
{
	int i;

	dev_info(dht11->dev, "[dht-diag] %d edges detected:\n", dht11->num_edges);
	for (i = 1; i < dht11->num_edges; ++i) {
		dev_info(dht11->dev, "[dht-diag] %d: %lld ns %s\n", i,
			dht11->edges[i].ts - dht11->edges[i - 1].ts,
			/* Keep debug labels compatible with the legacy 6.1 kernel;
			 * string_choices.h exists only in newer kernel headers. */
			dht11->edges[i - 1].value ? "high" : "low");
	}
}

static unsigned char dht11_decode_byte(char *bits)
{
	unsigned char ret = 0;
	int i;

	for (i = 0; i < 8; ++i) {
		ret <<= 1;
		if (bits[i])
			++ret;
	}

	return ret;
}

/* Derive a threshold only when this frame has two clearly separated pulse
 * populations.  The DHT22 has no clock for a PLL to lock onto; adapting to
 * the largest measured gap compensates for cable/IRQ timing drift without
 * guessing missing edges.  Weakly separated frames retain the conservative
 * fixed threshold and still have to pass the checksum. */
static int dht11_adaptive_threshold(const struct dht11_edge *edges, int offset)
{
	long long widths[DHT11_BITS_PER_READ];
	long long largest_gap = 0;
	long long low_median = 0, high_median = 0;
	int i, j, split = -1, threshold = DHT11_THRESHOLD;

	for (i = 0; i < DHT11_BITS_PER_READ; ++i)
		widths[i] = edges[offset + 2 * i + 2].ts -
			edges[offset + 2 * i + 1].ts;
	/* Insertion sort is deterministic and tiny (40 pulse widths). */
	for (i = 1; i < DHT11_BITS_PER_READ; ++i) {
		long long value = widths[i];
		for (j = i; j > 0 && widths[j - 1] > value; --j)
			widths[j] = widths[j - 1];
		widths[j] = value;
	}
	for (i = 8; i <= DHT11_BITS_PER_READ - 8; ++i) {
		long long gap = widths[i] - widths[i - 1];
		if (gap > largest_gap) {
			largest_gap = gap;
			split = i;
		}
	}
	/* Use medians of both sufficiently populated groups. This rejects a
	 * single timing outlier that would otherwise move the threshold. */
	if (split >= 0 && largest_gap >= 15000) {
		low_median = widths[(split - 1) / 2];
		high_median = widths[split + (DHT11_BITS_PER_READ - split - 1) / 2];
		threshold = (int)((low_median + high_median) / 2);
	}
	/* Require separated, physically plausible pulse populations. */
	if (split < 0 || largest_gap < 15000 || low_median < 15000 ||
		low_median > 40000 || high_median < 50000 || high_median > 90000 ||
		threshold < 35000 || threshold > 60000)
		return DHT11_THRESHOLD;
	return threshold;
}

static int dht11_decode(struct dht11 *dht11, int offset)
{
	int i, t, threshold;
	char bits[DHT11_BITS_PER_READ];
	unsigned char temp_int, temp_dec, hum_int, hum_dec, checksum;
	int temperature, humidity;

	/* Check framing independently of checksum. A shifted preamble bit can
	 * satisfy the additive checksum for some payloads, but is not data.
	 */
	if (!dht11_frame_valid(dht11->edges, dht11->num_edges, offset)) {
		if (dht11_diag_active())
			dev_info(dht11->dev, "[dht-diag] frame rejected offset=%d\n", offset);
		return -EIO;
	}
	threshold = dht11_adaptive_threshold(dht11->edges, offset);

	for (i = 0; i < DHT11_BITS_PER_READ; ++i) {
		t = dht11->edges[offset + 2 * i + 2].ts -
			dht11->edges[offset + 2 * i + 1].ts;
		if (!dht11->edges[offset + 2 * i + 1].value) {
			dev_dbg(dht11->dev,
				"lost synchronisation at edge %d\n",
				offset + 2 * i + 1);
			return -EIO;
		}
		bits[i] = t > threshold;
	}

	hum_int = dht11_decode_byte(bits);
	hum_dec = dht11_decode_byte(&bits[8]);
	temp_int = dht11_decode_byte(&bits[16]);
	temp_dec = dht11_decode_byte(&bits[24]);
	checksum = dht11_decode_byte(&bits[32]);
	if (dht11_diag_active())
		dev_info(dht11->dev, "[dht-diag] offset=%d bytes=%u,%u,%u,%u checksum=%u\n",
			 offset, hum_int, hum_dec, temp_int, temp_dec, checksum);

	if (((hum_int + hum_dec + temp_int + temp_dec) & 0xff) != checksum) {
		if (dht11->decode_failure < DHT11_DECODE_CHECKSUM)
			dht11->decode_failure = DHT11_DECODE_CHECKSUM;
		dev_dbg(dht11->dev, "invalid checksum\n");
		return -EIO;
	}

	if (hum_int < 4) {  /* DHT22: 100000 = (3*256+232)*100 */
		temperature = (((temp_int & 0x7f) << 8) + temp_dec) *
					((temp_int & 0x80) ? -100 : 100);
		humidity = ((hum_int << 8) + hum_dec) * 100;
		/* DHT22 operating range: -40..80 C and 0..100 %RH. */
		if (temperature < -40000 || temperature > 80000 ||
			humidity < 0 || humidity > 100000) {
			dht11->decode_failure = DHT11_DECODE_FORMAT;
			dev_dbg(dht11->dev, "out-of-range DHT22 data: %d %d\n",
				temperature, humidity);
			return -EIO;
		}
	} else if (temp_dec < 10 && hum_dec < 10) {  /* DHT11 */
		temperature = temp_int * 1000 + temp_dec * 100;
		humidity = hum_int * 1000 + hum_dec * 100;
		if (temperature < 0 || temperature > 50000 ||
			humidity < 0 || humidity > 100000) {
			dht11->decode_failure = DHT11_DECODE_FORMAT;
			dev_dbg(dht11->dev, "out-of-range DHT11 data: %d %d\n",
				temperature, humidity);
			return -EIO;
		}
	} else {
		dht11->decode_failure = DHT11_DECODE_FORMAT;
		dev_err(dht11->dev,
			"Don't know how to decode data: %d %d %d %d\n",
			hum_int, hum_dec, temp_int, temp_dec);
		return -EIO;
	}
	dht11->temperature = temperature;
	dht11->humidity = humidity;

	/* Only accepted values refresh the 2s cache, never a rejected payload. */
	dht11->timestamp = ktime_get_boottime_ns();
	return 0;
}

/*
 * IRQ handler called on GPIO edges
 */
static irqreturn_t dht11_handle_irq(int irq, void *data)
{
	struct iio_dev *iio = data;
	struct dht11 *dht11 = iio_priv(iio);

	if (dht11->num_edges < DHT11_EDGES_PER_READ && dht11->num_edges >= 0) {
		s64 now = ktime_get_boottime_ns();
		int value = gpiod_get_value(dht11->gpiod);

		/* A captured host-release rising edge precedes the response.
		 * Keeping it fills the fixed buffer one edge too early. Drop it
		 * only after its short duration and the following falling edge
		 * are known. A ~80us preamble high (missed initial falling edge)
		 * remains available to the original short-frame decoder.
		 */
		if (dht11->num_edges == 1 &&
		    dht11_drop_start_rise(dht11->num_edges,
					dht11->edges[0].value, value,
					now - dht11->edges[0].ts))
			dht11->num_edges = 0;
		dht11->edges[dht11->num_edges].ts = now;
		dht11->edges[dht11->num_edges++].value = value;

		/* Complete preamble confirmation permits a longer release pulse.
		 * Three assignments only, no allocation/logging in the IRQ path.
		 */
		if (dht11_confirmed_release(dht11->edges, dht11->num_edges)) {
			dht11->edges[0] = dht11->edges[1];
			dht11->edges[1] = dht11->edges[2];
			dht11->edges[2] = dht11->edges[3];
			dht11->num_edges = 3;
		}

		if (dht11->num_edges >= DHT11_EDGES_PER_READ)
			complete(&dht11->completion);
	}

	return IRQ_HANDLED;
}

static int dht11_read_raw(struct iio_dev *iio_dev,
			  const struct iio_chan_spec *chan,
			int *val, int *val2, long m)
{
	struct dht11 *dht11 = iio_priv(iio_dev);
	int ret, timeres, offset;
	bool acquisition = false;
	enum dht11_counter failure = DHT11_STAT_IO_ERRORS;

	mutex_lock(&dht11->lock);
	dht11->counters.value[DHT11_STAT_READS]++;
	if (dht11->timestamp + DHT11_DATA_VALID_TIME < ktime_get_boottime_ns()) {
		acquisition = true;
		dht11->counters.value[DHT11_STAT_ACQUISITIONS]++;
		timeres = ktime_get_resolution_ns();
		dev_dbg(dht11->dev, "current timeresolution: %dns\n", timeres);
		if (timeres > DHT11_MIN_TIMERES) {
			dev_err(dht11->dev, "timeresolution %dns too low\n",
				timeres);
			/* In theory a better clock could become available
			 * at some point ... and there is no error code
			 * that really fits better.
			 */
			ret = -EAGAIN;
			failure = DHT11_STAT_CLOCK_ERRORS;
			goto err;
		}
		if (timeres > DHT11_AMBIG_LOW && timeres < DHT11_AMBIG_HIGH)
			dev_warn(dht11->dev,
				 "timeresolution: %dns - decoding ambiguous\n",
				 timeres);

		reinit_completion(&dht11->completion);

		dht11->num_edges = 0;
		ret = gpiod_direction_output(dht11->gpiod, 0);
		if (ret)
			goto err;
		dht11->counters.value[DHT11_STAT_WIRE_STARTS]++;
		usleep_range(DHT11_START_TRANSMISSION_MIN,
			     DHT11_START_TRANSMISSION_MAX);
		ret = gpiod_direction_input(dht11->gpiod);
		if (ret)
			goto err;

		ret = request_irq(dht11->irq, dht11_handle_irq,
				  IRQF_TRIGGER_RISING | IRQF_TRIGGER_FALLING,
				  iio_dev->name, iio_dev);
		if (ret)
			goto err;

		ret = wait_for_completion_killable_timeout(&dht11->completion,
							   HZ);

		free_irq(dht11->irq, iio_dev);

		if (dht11_diag_active())
			dht11_edges_print(dht11);

		/* A deadline with 82 edges can still decode successfully. Record the
		 * wait event separately from failed acquisitions, not as an error.
		 */
		if (ret == 0)
			dht11->counters.value[DHT11_STAT_WAIT_DEADLINES]++;
		if (ret < 0)
			failure = DHT11_STAT_INTERRUPTED;
		if (ret == 0 && dht11->num_edges < DHT11_EDGES_PER_READ - 1) {
			failure = dht11->num_edges == 0 ? DHT11_STAT_TIMEOUT_NO_EDGES
				: DHT11_STAT_TIMEOUT_INCOMPLETE;
			dev_err(dht11->dev, "Only %d signal edges detected\n",
				dht11->num_edges);
			ret = -ETIMEDOUT;
		}
		if (ret < 0)
			goto err;

		offset = DHT11_EDGES_PREAMBLE +
				dht11->num_edges - DHT11_EDGES_PER_READ;
		dht11->decode_failure = DHT11_DECODE_FRAME;
		for (; offset >= 0; --offset) {
			ret = dht11_decode(dht11, offset);
			if (dht11_diag_active())
				dev_info(dht11->dev, "[dht-diag] offset=%d result=%d\n", offset, ret);
			if (!ret)
				break;
		}

		if (ret) {
			switch (dht11->decode_failure) {
			case DHT11_DECODE_CHECKSUM: failure = DHT11_STAT_CHECKSUM_ERRORS; break;
			case DHT11_DECODE_FORMAT: failure = DHT11_STAT_FORMAT_ERRORS; break;
			default: failure = DHT11_STAT_FRAME_ERRORS; break;
			}
			goto err;
		}
		dht11->counters.value[DHT11_STAT_SUCCESSES]++;
	} else {
		dht11->counters.value[DHT11_STAT_CACHE_HITS]++;
	}

	ret = IIO_VAL_INT;
	if (chan->type == IIO_TEMP)
		*val = dht11->temperature;
	else if (chan->type == IIO_HUMIDITYRELATIVE)
		*val = dht11->humidity;
	else
		ret = -EINVAL;
err:
	if (acquisition && ret < 0) {
		dht11->counters.value[DHT11_STAT_FAILURES]++;
		dht11->counters.value[failure]++;
	}
	dht11->num_edges = -1;
	mutex_unlock(&dht11->lock);
	return ret;
}

/* One coherent read-only snapshot, including on 32-bit ARM. No debugfs,
 * logging, allocation, reset operation or extra sensor read is required.
 */
static ssize_t diagnostic_counters_show(struct device *dev,
				       struct device_attribute *attr, char *buf)
{
	struct dht11 *dht11 = iio_priv(dev_to_iio_dev(dev));
	ssize_t len = 0;

	mutex_lock(&dht11->lock);
	len += sysfs_emit_at(buf, len, "schema=dht11-counters-v1\n");
#define DHT11_COUNTER_PRINT(symbol, name) \
	len += sysfs_emit_at(buf, len, #name "=%llu\n", \
			    dht11->counters.value[DHT11_STAT_##symbol]);
	DHT11_COUNTER_FIELDS(DHT11_COUNTER_PRINT)
#undef DHT11_COUNTER_PRINT
	mutex_unlock(&dht11->lock);
	return len;
}

static IIO_DEVICE_ATTR_RO(diagnostic_counters, 0);
static struct attribute *dht11_attrs[] = {
	&iio_dev_attr_diagnostic_counters.dev_attr.attr,
	NULL,
};
static const struct attribute_group dht11_attribute_group = {
	.attrs = dht11_attrs,
};

static const struct iio_info dht11_iio_info = {
	.read_raw		= dht11_read_raw,
	.attrs			= &dht11_attribute_group,
};

static const struct iio_chan_spec dht11_chan_spec[] = {
	{ .type = IIO_TEMP,
		.info_mask_separate = BIT(IIO_CHAN_INFO_PROCESSED), },
	{ .type = IIO_HUMIDITYRELATIVE,
		.info_mask_separate = BIT(IIO_CHAN_INFO_PROCESSED), }
};

static const struct of_device_id dht11_dt_ids[] = {
	{ .compatible = "dht11", },
	{ }
};
MODULE_DEVICE_TABLE(of, dht11_dt_ids);

static int dht11_probe(struct platform_device *pdev)
{
	struct device *dev = &pdev->dev;
	struct dht11 *dht11;
	struct iio_dev *iio;

	iio = devm_iio_device_alloc(dev, sizeof(*dht11));
	if (!iio)
		return -ENOMEM;

	dht11 = iio_priv(iio);
	dht11->dev = dev;
	dht11->gpiod = devm_gpiod_get(dev, NULL, GPIOD_IN);
	if (IS_ERR(dht11->gpiod))
		return PTR_ERR(dht11->gpiod);

	dht11->irq = gpiod_to_irq(dht11->gpiod);
	if (dht11->irq < 0) {
		dev_err(dev, "GPIO %d has no interrupt\n", desc_to_gpio(dht11->gpiod));
		return -EINVAL;
	}

	dht11->timestamp = ktime_get_boottime_ns() - DHT11_DATA_VALID_TIME - 1;
	dht11->num_edges = -1;

	platform_set_drvdata(pdev, iio);

	init_completion(&dht11->completion);
	mutex_init(&dht11->lock);
	iio->name = pdev->name;
	iio->info = &dht11_iio_info;
	iio->modes = INDIO_DIRECT_MODE;
	iio->channels = dht11_chan_spec;
	iio->num_channels = ARRAY_SIZE(dht11_chan_spec);

	return devm_iio_device_register(dev, iio);
}

static struct platform_driver dht11_driver = {
	.driver = {
		.name	= "dht11",
		.of_match_table = dht11_dt_ids,
	},
	.probe  = dht11_probe,
};

module_platform_driver(dht11_driver);

MODULE_AUTHOR("Harald Geyer <harald@ccbib.org>");
MODULE_DESCRIPTION("DHT11 humidity/temperature sensor driver");
MODULE_LICENSE("GPL v2");
