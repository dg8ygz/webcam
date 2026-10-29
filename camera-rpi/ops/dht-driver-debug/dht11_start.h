/* SPDX-License-Identifier: GPL-2.0-or-later */
/* Ignore only a short initial release pulse, never a complete preamble high.
 * Observed release pulses: 4..12 us; DHT preamble high: about 80 us.
 * This predicate is shared verbatim with the userspace replay tests.
 */
static inline int dht11_drop_start_rise(int count, int previous_value,
				      int value, long long elapsed_ns)
{
	return count == 1 && previous_value == 1 && value == 0 &&
		elapsed_ns > 0 && elapsed_ns < 20000;
}
