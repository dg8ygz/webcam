/* SPDX-License-Identifier: GPL-2.0-or-later */
#ifndef CAMERA_RPI_DHT11_COUNTERS_H
#define CAMERA_RPI_DHT11_COUNTERS_H

/* Per-device RAM only. Updated/read under the existing device mutex, never
 * in the edge IRQ. A snapshot does not sample GPIO or refresh the value cache.
 * Keep this schema shared with native tests; all values are unsigned 64-bit.
 */
#define DHT11_COUNTER_FIELDS(X) \
	X(READS, reads) \
	X(CACHE_HITS, cache_hits) \
	X(ACQUISITIONS, acquisitions) \
	X(WIRE_STARTS, wire_starts) \
	X(SUCCESSES, successes) \
	X(FAILURES, failures) \
	X(WAIT_DEADLINES, wait_deadlines) \
	X(TIMEOUT_NO_EDGES, timeout_no_edges) \
	X(TIMEOUT_INCOMPLETE, timeout_incomplete) \
	X(FRAME_ERRORS, frame_errors) \
	X(CHECKSUM_ERRORS, checksum_errors) \
	X(FORMAT_ERRORS, format_errors) \
	X(INTERRUPTED, interrupted) \
	X(CLOCK_ERRORS, clock_errors) \
	X(IO_ERRORS, io_errors)

enum dht11_counter {
#define DHT11_COUNTER_ENUM(symbol, name) DHT11_STAT_##symbol,
	DHT11_COUNTER_FIELDS(DHT11_COUNTER_ENUM)
#undef DHT11_COUNTER_ENUM
	DHT11_STAT_COUNT
};

/* Preserve the deepest failed decode stage across fallback offsets: an
 * expected framing rejection at offset 1/0 must not hide a checksum failure
 * of the correctly aligned frame at offset 2. Count once per acquisition.
 */
enum dht11_decode_failure {
	DHT11_DECODE_FRAME,
	DHT11_DECODE_CHECKSUM,
	DHT11_DECODE_FORMAT
};

struct dht11_counters {
	unsigned long long value[DHT11_STAT_COUNT];
};

#endif
