/* SPDX-License-Identifier: GPL-2.0-or-later */
#ifndef CAMERA_RPI_DHT11_FRAME_H
#define CAMERA_RPI_DHT11_FRAME_H

/* Shared with the native replay tests; timestamps are nanoseconds. These
 * conservative windows are an experimental guard for the test Pi, not an
 * adaptive decoder. Nothing reconstructs a missing edge or checksum bit.
 */
struct dht11_edge {
	long long ts;
	int value;
};

static inline int dht11_preamble_pulse(long long duration)
{
	return duration >= 60000 && duration <= 110000;
}

/* A longer release pulse is removed only after BOTH response pulses arrived.
 * Unlike the existing <20us fast path, its duration alone is insufficient.
 * Missing initial falling edge starts with an ~80us high: never remove that.
 */
static inline int dht11_confirmed_release(const struct dht11_edge *edges,
					int count)
{
	return count == 4 && edges[0].value == 1 && edges[1].value == 0 &&
		edges[2].value == 1 && edges[3].value == 0 &&
		edges[1].ts > edges[0].ts &&
		edges[1].ts - edges[0].ts < 60000 &&
		dht11_preamble_pulse(edges[2].ts - edges[1].ts) &&
		dht11_preamble_pulse(edges[3].ts - edges[2].ts);
}

/* Admit exactly 40 complete low/high bit cells after an observed preamble.
 * 83 edges include both preamble pulses; 82 may omit its initial falling
 * edge, but must still contain its full high pulse. Offset fallback must not
 * reinterpret the preamble as the first data bit, even if checksum matches.
 */
static inline int dht11_frame_valid(const struct dht11_edge *edges,
				   int count, int offset)
{
	int i;

	if (!((count == 83 && offset == 2) || (count == 82 && offset == 1)))
		return 0;
	for (i = 1; i < count; ++i) {
		if (edges[i].ts <= edges[i - 1].ts ||
		    (edges[i].value != 0 && edges[i].value != 1) ||
		    edges[i].value == edges[i - 1].value)
			return 0;
	}
	if (edges[offset - 1].value != 1 || edges[offset].value != 0 ||
	    !dht11_preamble_pulse(edges[offset].ts - edges[offset - 1].ts))
		return 0;
	if (offset == 2 && (edges[0].value != 0 ||
	    !dht11_preamble_pulse(edges[1].ts - edges[0].ts)))
		return 0;
	for (i = offset; i + 2 < count; i += 2) {
		long long low = edges[i + 1].ts - edges[i].ts;
		long long high = edges[i + 2].ts - edges[i + 1].ts;

		if (low < 30000 || low > 80000 || high < 10000 || high > 100000)
			return 0;
	}
	return 1;
}
#endif
