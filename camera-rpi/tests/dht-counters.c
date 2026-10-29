/* SPDX-License-Identifier: GPL-2.0-or-later */
/* Use the actual decoder AND read_raw body, not a second accounting model. */
#define main frame_checks_main
#include "dht-frame.c"
#undef main
#include <stdbool.h>
#define DHT11_DATA_VALID_TIME 2000000000LL
#define DHT11_EDGES_PER_READ 83
#define DHT11_EDGES_PREAMBLE 2
#define DHT11_MIN_TIMERES 34000
#define DHT11_AMBIG_LOW 23000
#define DHT11_AMBIG_HIGH 30000
#define DHT11_START_TRANSMISSION_MIN 18000
#define DHT11_START_TRANSMISSION_MAX 20000
#define HZ 100
#define IRQF_TRIGGER_RISING 1
#define IRQF_TRIGGER_FALLING 2
#define IIO_VAL_INT 1
#define IIO_TEMP 1
#define IIO_HUMIDITYRELATIVE 2
#define dev_warn(...) ((void)0)
#define mutex_lock(...) ((void)0)
#define mutex_unlock(...) ((void)0)
#define reinit_completion(...) ((void)0)
#define usleep_range(...) ((void)0)
#define free_irq(...) ((void)0)
#define dht11_edges_print(...) ((void)0)
struct iio_dev { struct dht11 data; const char *name; };
struct iio_chan_spec { int type; };
#define iio_priv(d) (&(d)->data)
static int fake_timeres=1, output_rc, input_rc, irq_rc, wait_rc=1;
static struct dht11 next_frame;
#define ktime_get_resolution_ns() fake_timeres
#define gpiod_direction_output(...) output_rc
#define gpiod_direction_input(...) input_rc
#define request_irq(...) irq_rc
static int wait_frame(struct iio_dev *iio)
{
    memcpy(iio->data.edges,next_frame.edges,sizeof(next_frame.edges));
    iio->data.num_edges=next_frame.num_edges;
    return wait_rc;
}
#define wait_for_completion_killable_timeout(...) wait_frame(iio_dev)
#include "read-under-test.inc"

static void outcome(enum dht11_counter expected)
{
    struct iio_dev iio={.data={.timestamp=-3000000000LL}};
    struct iio_chan_spec chan={.type=IIO_TEMP}; int val=0;
    CHECK(dht11_read_raw(&iio,&chan,&val,NULL,0)<0);
    CHECK(iio.data.counters.value[DHT11_STAT_ACQUISITIONS]==1);
    CHECK(iio.data.counters.value[DHT11_STAT_FAILURES]==1);
    CHECK(iio.data.counters.value[DHT11_STAT_SUCCESSES]==0);
    CHECK(iio.data.counters.value[expected]==1);
    unsigned long long sum=0;
    for(int i=DHT11_STAT_TIMEOUT_NO_EDGES;i<DHT11_STAT_COUNT;i++) sum+=iio.data.counters.value[i];
    CHECK(sum==1); /* No triple counting from three fallback offsets. */
}
int main(int argc, char **argv)
{
    CHECK(frame_checks_main(argc,argv)==0);
    struct iio_dev iio={.data={.timestamp=-3000000000LL}};
    struct iio_chan_spec chan={.type=IIO_TEMP}; int val=0;
    next_frame=frame(2,80,0,236);
    CHECK(dht11_read_raw(&iio,&chan,&val,NULL,0)==IIO_VAL_INT);
    CHECK(val==23600);
    chan.type=IIO_HUMIDITYRELATIVE;
    CHECK(dht11_read_raw(&iio,&chan,&val,NULL,0)==IIO_VAL_INT);
    CHECK(val==59200);
    CHECK(iio.data.counters.value[DHT11_STAT_READS]==2);
    CHECK(iio.data.counters.value[DHT11_STAT_CACHE_HITS]==1);
    CHECK(iio.data.counters.value[DHT11_STAT_SUCCESSES]==1);
    CHECK(iio.data.counters.value[DHT11_STAT_WIRE_STARTS]==1);
    next_frame.edges[4].ts=next_frame.edges[3].ts; outcome(DHT11_STAT_FRAME_ERRORS);
    next_frame=frame(2,80,0,236);
    /* Flip a checksum bit but preserve framing and all later pulse widths. */
    long long delta=next_frame.edges[82].ts-next_frame.edges[81].ts>49000 ? -45000 : 45000;
    next_frame.edges[82].ts+=delta; outcome(DHT11_STAT_CHECKSUM_ERRORS);
    next_frame=frame(10,20,10,20); outcome(DHT11_STAT_FORMAT_ERRORS);
    next_frame=frame(2,80,0,236); wait_rc=0;
    next_frame.num_edges=0; outcome(DHT11_STAT_TIMEOUT_NO_EDGES);
    next_frame.num_edges=80; outcome(DHT11_STAT_TIMEOUT_INCOMPLETE);
    /* Valid 82-edge short frame at deadline is NOT a failed acquisition. */
    next_frame=frame(2,80,0,236);
    memmove(next_frame.edges,next_frame.edges+1,82*sizeof(next_frame.edges[0])); next_frame.num_edges=82;
    iio=(struct iio_dev){.data={.timestamp=-3000000000LL}};
    CHECK(dht11_read_raw(&iio,&chan,&val,NULL,0)==IIO_VAL_INT);
    CHECK(iio.data.counters.value[DHT11_STAT_WAIT_DEADLINES]==1);
    CHECK(iio.data.counters.value[DHT11_STAT_FAILURES]==0);
    wait_rc=-EINTR; outcome(DHT11_STAT_INTERRUPTED); wait_rc=1;
    fake_timeres=40000; outcome(DHT11_STAT_CLOCK_ERRORS); fake_timeres=1;
    output_rc=-EIO; outcome(DHT11_STAT_IO_ERRORS); output_rc=0;
    input_rc=-EIO; outcome(DHT11_STAT_IO_ERRORS); input_rc=0;
    irq_rc=-EBUSY; outcome(DHT11_STAT_IO_ERRORS);
    printf("OK: %d decoder/accounting checks, actual read_raw, no hardware\n",checks);
    return 0;
}
