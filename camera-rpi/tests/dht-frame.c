/* SPDX-License-Identifier: GPL-2.0-or-later */
/* Native tests include the ACTUAL byte/value decoder extracted by the PHP
 * runner; only the kernel API is stubbed. No GPIO, modules or services.
 */
#include <errno.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include "dht11_start.h"
#include "dht11_frame.h"
#include "dht11_counters.h"
#define DHT11_BITS_PER_READ 40
#define DHT11_THRESHOLD 49000
#define dev_info(...) ((void)0)
#define dev_dbg(...) ((void)0)
#define dev_err(...) ((void)0)
struct dht11 {
    struct dht11_edge edges[83];
    int num_edges, temperature, humidity;
    long long timestamp;
    enum dht11_decode_failure decode_failure;
    struct dht11_counters counters;
    int lock, completion, gpiod, irq;
};
static int dht11_diag_active(void) { return 0; }
static long long ktime_get_boottime_ns(void) { return 123456789; }
#include "decoder-under-test.inc"

static int checks;
#define CHECK(test) do { ++checks; if (!(test)) { \
    fprintf(stderr, "FAIL line %d: %s\n", __LINE__, #test); exit(1); } } while (0)

static struct dht11 frame(int a, int b, int c, int d)
{
    struct dht11 f = { .num_edges = 83, .timestamp = 7 };
    int bytes[] = {a, b, c, d, (a + b + c + d) & 255};
    long long t = 160000;
    f.edges[0] = (struct dht11_edge){0, 0};
    f.edges[1] = (struct dht11_edge){80000, 1};
    f.edges[2] = (struct dht11_edge){t, 0};
    for (int i = 0; i < 40; ++i) {
        f.edges[3 + i*2] = (struct dht11_edge){t += 51000, 1};
        t += bytes[i/8] & (1 << (7-i%8)) ? 71000 : 26000;
        f.edges[4 + i*2] = (struct dht11_edge){t, 0};
    }
    return f;
}

/* Replay the bounded edge collection with the same two shared predicates.
 * This models IRQ ordering, not scheduling or interrupt timing on hardware.
 */
static struct dht11 capture(struct dht11 f, long long release)
{
    struct dht11 out = { .timestamp = 7 };
    out.edges[out.num_edges++] = (struct dht11_edge){-release, 1};
    for (int i = 0; i < f.num_edges && out.num_edges < 83; ++i) {
        if (out.num_edges == 1 && dht11_drop_start_rise(1,
            out.edges[0].value, f.edges[i].value, f.edges[i].ts-out.edges[0].ts))
            out.num_edges = 0;
        out.edges[out.num_edges++] = f.edges[i];
        if (dht11_confirmed_release(out.edges, out.num_edges)) {
            memmove(out.edges, out.edges+1, 3*sizeof(out.edges[0]));
            out.num_edges = 3;
        }
    }
    return out;
}

int main(int argc, char **argv)
{
    struct dht11 f = frame(2,80,0,236), bad;
    CHECK(dht11_decode(&f,2) == 0);
    CHECK(f.temperature == 23600 && f.humidity == 59200);
    CHECK(f.timestamp == 123456789);
    CHECK(dht11_decode(&f,1) == -EIO);
    CHECK(dht11_decode(&f,0) == -EIO);
    for (int i = 0; i < 83; ++i) {
        bad = frame(2,80,0,236); bad.edges[i].value ^= 1;
        CHECK(dht11_decode(&bad,2) == -EIO && bad.timestamp == 7);
    }
    for (int i = 1; i < 83; ++i) {
        bad = frame(2,80,0,236); bad.edges[i].ts = bad.edges[i-1].ts;
        CHECK(dht11_decode(&bad,2) == -EIO && bad.timestamp == 7);
    }
    bad = frame(2,80,0,236); bad.edges[82].ts += 45000;
    CHECK(dht11_frame_valid(bad.edges,83,2));
    CHECK(dht11_decode(&bad,2) == -EIO && bad.timestamp == 7);
    bad = frame(129,40,0,118); /* valid checksum, unsupported data */
    CHECK(dht11_decode(&bad,2) == -EIO && bad.timestamp == 7);
    bad = frame(1,244,128,50);
    CHECK(dht11_decode(&bad,2) == 0 && bad.temperature == -5000);
    bad = frame(55,0,23,0);
    CHECK(dht11_decode(&bad,2) == 0 && bad.humidity == 55000);
    bad = frame(2,80,0,236);
    memmove(bad.edges,bad.edges+1,82*sizeof(bad.edges[0])); bad.num_edges=82;
    CHECK(dht11_decode(&bad,1) == 0);
    CHECK(!dht11_confirmed_release(bad.edges,4));
    --bad.num_edges;
    CHECK(dht11_decode(&bad,1) == -EIO);
    long long releases[] = {1, 8000, 19999, 20000, 30000, 59999};
    for (unsigned i=0; i<sizeof(releases)/sizeof(releases[0]); ++i) {
        bad = capture(f,releases[i]);
        CHECK(dht11_decode(&bad,2) == 0 && bad.temperature == 23600);
    }
    bad = capture(f,60000); /* boundary: no inferred release */
    CHECK(dht11_decode(&bad,2) == -EIO);
    CHECK(dht11_decode(&bad,1) == -EIO);
    for (int i=0;i<4;++i) {
        struct dht11_edge prefix[]={{0,1},{30000,0},{110000,1},{190000,0}};
        prefix[i].value ^= 1;
        CHECK(!dht11_confirmed_release(prefix,4));
    }
    struct dht11_edge prefix[]={{0,1},{30000,0},{81000,1},{152000,0}};
    CHECK(!dht11_confirmed_release(prefix,4)); /* data low, not preamble */
    CHECK(!dht11_confirmed_release(prefix,3));
    bad = frame(2,80,0,236); bad.edges[1].ts=59000;
    CHECK(dht11_decode(&bad,2) == -EIO);
    bad = frame(2,80,0,236); bad.edges[3].ts=bad.edges[2].ts+29000;
    CHECK(dht11_decode(&bad,2) == -EIO);
    bad = frame(2,80,0,236); bad.edges[4].ts=bad.edges[3].ts+9000;
    CHECK(dht11_decode(&bad,2) == -EIO);

    /* Real recorded intervals from six successful test-Pi transactions.
     * Final falling value is low, as implied by the decoder's success.
     * The diagnostic format does not print that final sampled value itself.
     */
    CHECK(argc == 2);
    FILE *input=fopen(argv[1],"r"); CHECK(input != NULL);
    int index, level, frames=0; long long delta;
    memset(&f,0,sizeof(f)); f.num_edges=83;
    while (fscanf(input,"%d %lld %d",&index,&delta,&level)==3) {
        CHECK(index >= 1 && index <= 82);
        if (index==1) { memset(&f,0,sizeof(f)); f.num_edges=83; }
        f.edges[index-1].value=level;
        f.edges[index].ts=f.edges[index-1].ts+delta;
        if (index==82) { CHECK(dht11_decode(&f,2)==0); ++frames; }
    }
    CHECK(feof(input)); fclose(input); CHECK(frames==6);
    printf("OK: %d frame checks; actual decoder, six recorded frames; no GPIO\n",checks);
    return 0;
}
