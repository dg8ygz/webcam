/* SPDX-License-Identifier: GPL-2.0-or-later */
#include "../ops/dht-driver-debug/dht11_start.h"
int drop_start(int count, int previous, int value, long long elapsed)
{
    return dht11_drop_start_rise(count, previous, value, elapsed);
}
