/* GPIO actuator for the PHP enclosure controller, not a second regulator.
 * Linux GPIO character API v2 (>=5.10); selected header pins, LOW-active heater.
 * No network, config parser, DHT reads or shell execution; header pins only.
 * stdin: "SET heater(0/1) duty(0..1000)\n"; stdout: "OK heater duty\n".
 * EOF, invalid input, signal, I/O error or 3 seconds without a valid SET => off.
 * A software watchdog cannot replace external pull-up / independent thermal fuse.
 */
#define _GNU_SOURCE
#include <errno.h>
#include <fcntl.h>
#include <glob.h>
#include <linux/gpio.h>
#include <poll.h>
#include <signal.h>
#include <stdint.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <sys/ioctl.h>
#include <sys/socket.h>
#include <sys/stat.h>
#include <sys/un.h>
#include <time.h>
#include <unistd.h>

static volatile sig_atomic_t stopping;
static int line_fd = -1;
static int test_mode;
static int focus_only;
#ifdef CLIMATE_TEST
#ifndef CAMERA_RPI_TEST_FOCUS_LEASE_NS
#define CAMERA_RPI_TEST_FOCUS_LEASE_NS 1000000000LL
#endif
static const int64_t focus_lease_ns = CAMERA_RPI_TEST_FOCUS_LEASE_NS;
static const int64_t reset_second_ns = 10000000LL; /* accelerated isolated tests */
#else
static const int64_t focus_lease_ns = 60000000000LL;
static const int64_t reset_second_ns = 1000000000LL;
#endif
static int last_bits = -1;
static const int64_t period = 10000000; /* 10ms = 100Hz; duty in tenths percent. */

static void stop_signal(int signum) { (void)signum; stopping = 1; }
static int64_t monotonic_ns(void)
{
    struct timespec ts;
    if (clock_gettime(CLOCK_MONOTONIC, &ts)) { stopping = 1; return 0; }
    return (int64_t)ts.tv_sec * 1000000000 + ts.tv_nsec;
}

/* One atomic request initializes BOTH outputs safely. The profile validator
 * excludes the sensor and camera-power pins from these climate assignments.
 * A foreign chip, occupied lines or unsupported kernel is a hard error.
 */
static int claim_pins(unsigned first, unsigned second, int power_only)
{
    glob_t matches = {0};
    if (glob("/dev/gpiochip*", 0, NULL, &matches)) { globfree(&matches); return -1; }
    for (size_t i=0; i<matches.gl_pathc; i++) {
        int fd = open(matches.gl_pathv[i], O_RDONLY | O_CLOEXEC | O_NOFOLLOW);
        if (fd < 0) continue;
        struct gpiochip_info info = {0};
        if (ioctl(fd, GPIO_GET_CHIPINFO_IOCTL, &info) == 0 && info.lines > 27 &&
            (!strcmp(info.label,"pinctrl-bcm2711") || !strcmp(info.label,"pinctrl-bcm2835"))) {
            struct gpio_v2_line_request request = {0};
            request.offsets[0]=first; request.offsets[1]=second;
            request.num_lines=power_only ? 1 : 2;
            strcpy(request.consumer, focus_only ? "camera-rpi-focus" : power_only ? "camera-rpi-power" : "camera-rpi-climate");
            request.config.flags = GPIO_V2_LINE_FLAG_OUTPUT;
            request.config.num_attrs = 1;
            request.config.attrs[0].attr.id = GPIO_V2_LINE_ATTR_ID_OUTPUT_VALUES;
            request.config.attrs[0].attr.values = power_only ? 0 : 1;
            request.config.attrs[0].mask = power_only ? 1 : 3;
            int rc = ioctl(fd, GPIO_V2_GET_LINE_IOCTL, &request);
            close(fd); globfree(&matches);
            return rc == 0 ? request.fd : -1;
        }
        close(fd);
    }
    globfree(&matches); return -1;
}

/* Constant 0/100% require no repetitive GPIO writes. Changing both levels in
 * one ioctl prevents a relay-on/fan-off interval during start/stop transitions.
 */
static int levels(unsigned heater, unsigned fan_high)
{
    int bits = (heater ? 0 : 1) | (fan_high ? 2 : 0);
    if (bits == last_bits) return 0;
    if (test_mode) { fprintf(stderr, "LEVEL %d\n", bits); }
    else {
        struct gpio_v2_line_values values = {.bits=(uint64_t)bits, .mask=3};
        if (ioctl(line_fd, GPIO_V2_LINE_SET_VALUES_IOCTL, &values)) return -1;
    }
    last_bits = bits;
    return 0;
}

/* Bounded decimal protocol: no signed values, overflows, trailing arguments or
 * heater-on with stopped fan. Parsing never evaluates a configuration string.
 */
static int parse_set(const char *line, unsigned *heater, unsigned *duty)
{
    if (strlen(line) < 7 || strncmp(line,"SET ",4) || (line[4]!='0' && line[4]!='1') || line[5]!=' ') return -1;
    const char *p=line+6;
    unsigned value=0, count=0;
    while (*p>='0' && *p<='9' && count<4) { value=value*10+(unsigned)(*p++-'0'); count++; }
    if (!count || *p || value>1000 || (line[4]=='1' && !value)) return -1;
    *heater=(unsigned)(line[4]-'0'); *duty=value;
    return 0;
}

/* Only exposed header GPIO2..27; no coercion, signs or arbitrary chip offsets. */
static int parse_pin(const char *s, unsigned *pin)
{
    if (!s || !*s || strlen(s)>2 || (s[0]=='0' && s[1])) return -1;
    unsigned n=0;
    for (;*s;s++) { if (*s<'0' || *s>'9') return -1; n=n*10+(unsigned)(*s-'0'); }
    if (n<2 || n>27) return -1;
    *pin=n; return 0;
}

/* Root-owned local socket, ON/OFF/STATUS and bounded RESET. The camera supply has no
 * climate watchdog: retain the explicit state until another command or boot.
 * ACK only after GPIO write. No explicit off pulse on service shutdown.
 */
static int single_level(int on)
{
    struct gpio_v2_line_values values={.bits=focus_only ? (uint64_t)on : (uint64_t)!on,.mask=1};
    if (test_mode) { fprintf(stderr,"%s %d\n",focus_only?"FOCUS":"POWER",on); return 0; }
    return ioctl(line_fd,GPIO_V2_LINE_SET_VALUES_IOCTL,&values);
}

static int camera_power_server(const char *path)
{
    struct sockaddr_un address = {.sun_family=AF_UNIX};
    if (!path || strlen(path)>=sizeof(address.sun_path)) return 2;
    strcpy(address.sun_path,path);
    struct stat old;
    if (!lstat(path,&old)) {
        if (!S_ISSOCK(old.st_mode) || old.st_uid!=geteuid() || unlink(path)) return 1;
    } else if (errno!=ENOENT) return 1;
    int server=socket(AF_UNIX,SOCK_STREAM|SOCK_CLOEXEC|SOCK_NONBLOCK,0);
    if (server<0) return 1;
    if (bind(server,(struct sockaddr*)&address,sizeof(address)) || chmod(path,0600) || listen(server,4)) {
        close(server); return 1;
    }
    int on=focus_only?0:1, rc=0;
    int64_t focus_deadline=0, reset_started=0;
    int64_t reset_off_ns=45*reset_second_ns, reset_ready_ns=60*reset_second_ns;
    if (test_mode) fprintf(stderr,"%s %d\n",focus_only?"FOCUS":"POWER",on);
    while (!stopping) {
        if (reset_started) {
            int64_t elapsed=monotonic_ns()-reset_started;
            if (!on && elapsed>=reset_off_ns) {
                if (single_level(1)) { rc=1; break; }
                on=1;
                fputs("Camera recovery: supply ON; waiting for USB\n",stderr);
            }
            if (elapsed>=reset_ready_ns) reset_started=0;
        }
        if (focus_only && on && monotonic_ns()>=focus_deadline) {
            if (single_level(0)) { rc=1; break; }
            on=0;
        }
        struct pollfd listener={.fd=server,.events=POLLIN};
        int ready=poll(&listener,1,(focus_only || reset_started)?50:1000);
        if (ready<0) { if (errno==EINTR) continue; rc=1; break; }
        if (!ready) continue;
        int client=accept4(server,NULL,NULL,SOCK_CLOEXEC|SOCK_NONBLOCK);
        if (client<0) continue;
        char input[16]={0}; size_t used=0;
        int64_t deadline=monotonic_ns()+1000000000LL;
        if (focus_only && on && focus_deadline<deadline) deadline=focus_deadline;
        if (reset_started) {
            int64_t reset_deadline=reset_started+(on?reset_ready_ns:reset_off_ns);
            if (reset_deadline<deadline) deadline=reset_deadline;
        }
        while (!stopping && used<sizeof(input)-1 && monotonic_ns()<deadline) {
            struct pollfd reader={.fd=client,.events=POLLIN};
            int ms=(int)((deadline-monotonic_ns())/1000000);
            if (ms<=0 || poll(&reader,1,ms)<=0) break;
            ssize_t n=read(client,input+used,sizeof(input)-1-used);
            if (n<=0) break;
            used+=(size_t)n;
            if (memchr(input,'\n',used)) break;
        }
        /* Owner, not the requesting worker, completes the power cycle even
         * after client death. RESET never enables a manually powered-off cam.
         * Explicit ON/OFF overrides and cancels this automatic lease. */
        /* A reset snapshots its duration once; later config saves cannot move
         * the deadline. Legacy RESET retains 45s. Reject partial/NUL commands. */
        unsigned off_seconds=45;
        int reset_command=used==6 && !memcmp(input,"RESET\n",6);
        if (used>=8 && used<=10 && !memcmp(input,"RESET ",6) && input[used-1]=='\n') {
            off_seconds=0; int digits=1;
            for (size_t i=6;i<used-1;i++) {
                if (input[i]<'0' || input[i]>'9') { digits=0; break; }
                off_seconds=off_seconds*10+(unsigned)(input[i]-'0');
            }
            reset_command=digits && input[6]!='0' && off_seconds>=10 && off_seconds<=300;
        }
        if (!focus_only && reset_command) {
            if (on && !reset_started && !stopping && !single_level(0)) {
                reset_off_ns=off_seconds*reset_second_ns;
                reset_ready_ns=(off_seconds+15)*reset_second_ns;
                on=0; reset_started=monotonic_ns();
                fprintf(stderr,"Camera recovery: supply OFF for %us; startup 15s (bounded reset)\n",off_seconds);
                (void)send(client,"RESET\n",6,MSG_NOSIGNAL);
            } else (void)send(client,"ERROR\n",6,MSG_NOSIGNAL);
            close(client); continue;
        }
        int next=on, valid=0, explicit_set=0;
        if (used==3 && !memcmp(input,"ON\n",3)) { next=1; valid=1; explicit_set=1; }
        if (used==4 && !memcmp(input,"OFF\n",4)) { next=0; valid=1; explicit_set=1; }
        if (used==7 && !memcmp(input,"STATUS\n",7)) valid=1;
        if (valid && !stopping) {
            if (focus_only && on && monotonic_ns()>=focus_deadline) {
                (void)send(client,"ERROR\n",6,MSG_NOSIGNAL);
                close(client); continue;
            }
            if (next!=on && single_level(next)) {
                (void)send(client,"ERROR\n",6,MSG_NOSIGNAL);
                if (focus_only) { rc=1; stopping=1; }
            } else {
                // Repeated ON/STATUS cannot keep a wedged focus job enabled.
                if (focus_only && next && !on) focus_deadline=monotonic_ns()+focus_lease_ns;
                if (explicit_set) reset_started=0;
                on=next;
                if (reset_started) (void)send(client,"RESET\n",6,MSG_NOSIGNAL);
                else (void)send(client,on?"ON\n":"OFF\n",on?3:4,MSG_NOSIGNAL);
            }
        } else (void)send(client,"ERROR\n",6,MSG_NOSIGNAL);
        close(client);
    }
    if (focus_only && single_level(0)) rc=1;
    if (reset_started && single_level(1)) rc=1;
    close(server); unlink(path); return rc;
}

int main(int argc, char **argv)
{
    if (argc==2 && !strcmp(argv[1],"--version")) { puts("camera-rpi-pwm-v1"); return 0; }
    unsigned heater_pin=5, fan_pin=27, power_pin=0;
    int power_only=(argc==3 || argc==4) && !strcmp(argv[1],"--power");
    if (argc==4 && !strcmp(argv[1],"--focus")) { power_only=1; focus_only=1; }
#ifdef CLIMATE_TEST
    if (argc==4 && !strcmp(argv[1],"--test-power")) { power_only=1; test_mode=1; }
    if (argc==4 && !strcmp(argv[1],"--test-focus")) { power_only=1; focus_only=1; test_mode=1; }
#endif
    if (power_only && parse_pin(argv[2],&power_pin)) return 2;
    if (!power_only && argc==4 && (parse_pin(argv[2],&heater_pin) || parse_pin(argv[3],&fan_pin)
        || heater_pin==fan_pin)) return 2;
#ifdef CLIMATE_TEST
    if ((argc==2 || argc==4) && !strcmp(argv[1],"--test")) test_mode=1;
#endif
    if (!test_mode && (geteuid()!=0 || (!power_only &&
        ((argc!=2 && argc!=4) || strcmp(argv[1],"--run"))))) {
        fputs("Usage: camera-rpi-pwm --run [HEATER FAN] | --power PIN [SOCKET] | --focus PIN SOCKET (root); --version\n",stderr); return 2;
    }
    struct sigaction action = {.sa_handler=stop_signal};
    sigemptyset(&action.sa_mask);
    sigaction(SIGTERM,&action,NULL); sigaction(SIGINT,&action,NULL);
    signal(SIGPIPE,SIG_IGN);
    if (!test_mode && (line_fd=claim_pins(power_only?power_pin:heater_pin,fan_pin,power_only))<0) {
        perror("BCM GPIO claim (pin already in use or unavailable)"); return 1;
    }
    /* Supply ownership is independent of climate. Hold LOW/on at startup; only
     * explicit socket commands may switch. On shutdown do not deliberately power off.
     */
    if (power_only) {
        int rc=0;
        if (argc==4) rc=camera_power_server(argv[3]);
        else while (!stopping) pause();
        if (line_fd>=0) close(line_fd);
        return rc;
    }
    /* Nonblocking input AND acknowledgements: a wedged parent must not stop PWM
     * or prevent the independent watchdog from switching the heater off.
     */
    fcntl(STDIN_FILENO,F_SETFL,O_NONBLOCK);
    fcntl(STDOUT_FILENO,F_SETFL,O_NONBLOCK);
    unsigned heater=0, duty=0;
    int rc=0, watchdog_expired=0;
    char buffer[32]; size_t used=0;
    int64_t now=monotonic_ns(), last_command=now, cycle=now;
    if (levels(0,0)) rc=1;
    while (!stopping && !rc) {
        now=monotonic_ns();
        if (now-last_command>=3000000000LL) { watchdog_expired=1; rc=1; break; }
        /* Skip missed periods instead of replaying edges after scheduler stalls. */
        if (now-cycle>=period) cycle += ((now-cycle)/period)*period;
        int64_t phase=now-cycle, high=(int64_t)duty*10000;
        if (levels(heater, duty==1000 || (duty && phase<high))) { rc=1; break; }
        int64_t deadline=last_command+3000000000LL;
        if (duty>0 && duty<1000) {
            int64_t edge=cycle+(phase<high?high:period);
            if (edge<deadline) deadline=edge;
        }
        int64_t ns=deadline-monotonic_ns(); if (ns<0) ns=0;
        struct timespec delay={.tv_sec=ns/1000000000,.tv_nsec=ns%1000000000};
        struct pollfd poller={.fd=STDIN_FILENO,.events=POLLIN};
        int ready=ppoll(&poller,1,&delay,NULL);
        if (ready<0) { if(errno==EINTR) continue; rc=1; break; }
        if (!ready) continue;
        char byte;
        ssize_t n=read(STDIN_FILENO,&byte,1);
        if (n==0) break;
        if (n<0) { if(errno==EAGAIN || errno==EINTR) continue; rc=1; break; }
        if (byte!='\n') {
            if (used>=sizeof(buffer)-1 || byte=='\0') {rc=1;break;}
            buffer[used++]=byte; continue;
        }
        buffer[used]='\0'; used=0;
        unsigned next_heater, next_duty;
        if (parse_set(buffer,&next_heater,&next_duty)) {rc=1;break;}
        now=monotonic_ns();
        if(next_duty!=duty) cycle=now;
        heater=next_heater; duty=next_duty; last_command=now;
        /* ACK only after applying this command; same-duty heartbeats do NOT
         * restart a PWM cycle. OFF applies immediately, before acknowledgement.
         */
        int64_t current_phase=(now-cycle)%period;
        if(levels(heater,duty==1000 || (duty && current_phase<(int64_t)duty*10000))) {rc=1;break;}
        char ack[32]; int len=snprintf(ack,sizeof(ack),"OK %u %u\n",heater,duty);
        if(write(STDOUT_FILENO,ack,(size_t)len)!=len) {rc=1;break;}
    }
    if(levels(0,0)) {fputs("PWM: final off failed; hardware check required\n",stderr);rc=1;}
    if(line_fd>=0) close(line_fd);
    if(watchdog_expired) fputs("PWM watchdog: no SET for 3s; outputs released\n",stderr);
    return rc;
}
