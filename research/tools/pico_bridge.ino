// NOTE (2026-11-11): the J2/J3 names in these comments are swapped. The board's UART0 console
// (U-Boot, vxWorks, mainline Linux) is J2, four bare holes under the RF shield; the factory
// 4-pin header J3 is UART1, the stock Linux console. See ../consoles-and-u-boot.md.
// Dual USB <-> UART bridge for the Drobo 5N consoles (Raspberry Pi Pico, 3.3V).
//
// Wiring, SWAP_PINS=1 (verified with tools/pico_listen after the 1x3 housings
// were swapped between the headers on the evening of 2026-11-09):
//   USB CDC port #1 (Serial, /dev/cu.usbmodem1301) <-> GP4(rx)/GP5(tx) <-> vxWorks/U-Boot console (J3)
//   USB CDC port #2 (usbJ2,  /dev/cu.usbmodem1303) <-> GP0(rx)/GP1(tx) <-> Linux console (J2)
// The housings were swapped again on 2026-11-10 (the J3 housing's ground crimp
// had failed); the channel->pin mapping below follows them so the ports keep
// their meaning. Each housing: red = Pico rx on header pin 3, green = Pico tx
// on pin 2. Ground now also runs through a separate wire to the board.
// Pin roles must match the wiring: a wrong guess drives a Pico TX pin against
// the board's TX. Check an unknown wiring with tools/pico_listen first.
// The variable names (uartJ3/uartJ2, usbJ2) are historical; trust the lines above.
// GND (pin 3 / pin 8) -> J3 pin 4 / J2 pad 4. Fixed 115200 8N1.
//
// Build with the arduino-pico core and the Adafruit TinyUSB stack:
//   arduino-cli compile --fqbn rp2040:rp2040:rpipico:usbstack=tinyusb
//
// Board -> host data is dropped (not blocked on) if that USB port isn't open,
// so one console never stalls the other.
//
// Power gating: the Pico is USB-powered, so a TX pin idling at 3.3V would
// back-power the Drobo through its RX pin while the board is off. On
// 2026-11-09 that made the board's PMU shut it down about a minute into every
// boot. So each TX pin stays high-impedance until that channel's RX line (the
// board's own TX, idle-high only while the board is powered) has been high for
// POWER_ON_MS. It goes high-impedance again once RX has been low for
// POWER_OFF_MS, which is far longer than any UART character. Host -> board
// bytes are discarded while gated. RX pins get a pull-down, not the library's
// pull-up, so they source no current into the board and an unpowered board
// reads low.
//
// Front panel (added 2026-11-10), read-only probe of the 3-wire panel cable,
// soldered directly in place of the case button/LED:
//   GP26/ADC0 <- Red    GP27/ADC1 <- White    GP28/ADC2 <- Blue
// Measured 2026-11-10: none of the three is ground. White and Blue both idle
// near 3.17 V on standby and the case button joins them (both then read about
// 2.1 V); Blue drops to ~0 V once the board runs. Red is the LED, driven with
// a ramping blink pattern up to about 1.8 V. All three stay analog inputs.
// The press goes through an optocoupler (PC817-style): GP15 -> 330-470R ->
// opto LED, cathode -> GND; opto collector/emitter across White and Blue.
// GP15 high = pressed. GP15 only ever connects to the opto's LED.
// Host control bytes (never forwarded to the board):
//   Ctrl-\ (0x1C) short press, 300 ms   Ctrl-^ (0x1E) long press, 6 s
//   Ctrl-_ (0x1F) release now
// Every 5 ms the three voltages are sampled; a change of more than 300 mV on
// any of them prints "#adc t=<ms> r=<mV> w=<mV> b=<mV>" on USB port #2 (the
// Linux console, normally quiet). The status line shows the latest values.
//
// Onboard LED: mirrors the Drobo power LED, with a 40 ms inverted flicker every
// 2 s. A steady LED (on or off) means the main loop has stopped. A hardware
// watchdog reboots the Pico after WDT_MS without a
// loop pass; after such a reboot the Pico writes one line "#pico watchdog
// reboot" to both ports once a host opens them.

#include <Adafruit_TinyUSB.h>
#include <hardware/watchdog.h>
#define STR_(x) #x
#define STR(x) STR_(x)


Adafruit_USBD_CDC usbJ2;

// SWAP_PINS=1: run both UARTs on PIO with TX/RX exchanged (GP1/GP5 transmit,
// GP0/GP4 receive), for testing whether the wires went on the wrong way round.
#ifndef SWAP_PINS
#define SWAP_PINS 1   // matches the bench wiring (see header)
#endif
// Both modes use PIO UARTs; only the pin roles differ.
#if SWAP_PINS
SerialPIO uartJ3(5, 4, 8192);   // tx=GP5, rx=GP4
SerialPIO uartJ2(1, 0, 8192);   // tx=GP1, rx=GP0
#else
SerialPIO uartJ3(0, 1, 8192);   // tx=GP0, rx=GP1
SerialPIO uartJ2(4, 5, 8192);   // tx=GP4, rx=GP5
#endif
#define UART_J3 uartJ3
#define UART_J2 uartJ2

#ifndef POWER_ON_MS
#define POWER_ON_MS 20
#endif
#ifndef POWER_OFF_MS
#define POWER_OFF_MS 300
#endif

#if SWAP_PINS
static const uint8_t TX_PIN[2] = {5, 1}, RX_PIN[2] = {4, 0};
#else
static const uint8_t TX_PIN[2] = {0, 4}, RX_PIN[2] = {1, 5};
#endif
static bool boardOn[2];
static uint32_t lineSince[2];   // millis() when the RX level last changed
static bool lineHigh[2];

static void setTx(int idx, bool on) {
  boardOn[idx] = on;
  gpio_set_oeover(TX_PIN[idx], on ? GPIO_OVERRIDE_NORMAL : GPIO_OVERRIDE_LOW);
}

#ifndef WDT_MS
#define WDT_MS 2000
#endif


static bool wdReboot, wdNoted[2];
static void noteReboot(Adafruit_USBD_CDC &usb, int idx) {
  if (wdReboot && !wdNoted[idx] && usb) {
    usb.write("\r\n#pico watchdog reboot\r\n");
    wdNoted[idx] = true;
  }
}

static void powerGate(int idx) {
  bool high = gpio_get(RX_PIN[idx]);
  uint32_t now = millis();
  if (high != lineHigh[idx]) { lineHigh[idx] = high; lineSince[idx] = now; }
  uint32_t held = now - lineSince[idx];
  if (!boardOn[idx] && high && held >= POWER_ON_MS) setTx(idx, true);
  else if (boardOn[idx] && !high && held >= POWER_OFF_MS) setTx(idx, false);
}

static uint32_t rxCount[2], dropCount[2], txCount[2], gatedDrop[2], loops, loopsPrev, loopsAt;

// Status line, sent only when the host types Ctrl-] (0x1D) on that port.
// 0x1D is never forwarded to the board.
static void sendStatus(Adafruit_USBD_CDC &usb);
#define DIAG_KEY 0x1D
#define PRESS_SHORT_KEY 0x1C
#define PRESS_LONG_KEY 0x1E
#define RELEASE_KEY 0x1F
static const uint8_t ADC_PIN[3] = {26, 27, 28};   // red, white, blue
static int mv[3], mvRep[3];
static uint32_t adcAt;
static bool ledLevel;           // red above 1 V
static uint32_t ledChanges;
#define OPTO_PIN 15
static bool pressing;
static uint32_t pressUntil;
static void press(uint32_t ms) { digitalWrite(OPTO_PIN, HIGH); pressing = true; pressUntil = millis() + ms; }
static void release() { digitalWrite(OPTO_PIN, LOW); pressing = false; }

static void panel() {
  uint32_t now = millis();
  if (pressing && (int32_t)(now - pressUntil) >= 0) release();
  if (now - adcAt < 5) return;
  adcAt = now;
  bool big = false;
  for (int i = 0; i < 3; i++) {
    mv[i] = analogRead(ADC_PIN[i]) * 3300 / 4095;
    if (abs(mv[i] - mvRep[i]) > 300) big = true;
  }
  bool l = mv[0] > 1000;
  if (l != ledLevel) { ledLevel = l; ledChanges++; }
  if (big) {
    for (int i = 0; i < 3; i++) mvRep[i] = mv[i];
    if (usbJ2) {
      char t[64];
      snprintf(t, sizeof(t), "\r\n#adc t=%lu r=%d w=%d b=%d\r\n", (unsigned long)now, mv[0], mv[1], mv[2]);
      if (usbJ2.availableForWrite() >= (int)strlen(t)) usbJ2.write(t);
    }
  }
}

// The Pico's LED mirrors the Drobo's power LED (GP10), with a 40 ms inverted
// flicker every 2 s to show the loop is alive. A steady LED means it stopped.
static void heartbeat() {
  bool blip = (millis() % 2000) < 40;
  digitalWrite(LED_BUILTIN, (ledLevel ^ blip) ? HIGH : LOW);
}


static void pump(Stream &uart, Adafruit_USBD_CDC &usb, int idx) {
  uint8_t buf[256];

  // board -> host
  int n = uart.available();
  if (n > 0) {
    if (n > (int)sizeof(buf)) n = sizeof(buf);
    n = uart.readBytes(buf, n);
    rxCount[idx] += n;
    if (!usb) dropCount[idx] += n;
    if (usb) {                         // host has the port open (DTR)
      int room = usb.availableForWrite();
      if (room < n) n = room;          // drop the excess rather than block
      if (n > 0) usb.write(buf, n);
    }
  }

  // host -> board (discarded while the board is unpowered)
  n = usb.available();
  if (n > 0) {
    if (n > (int)sizeof(buf)) n = sizeof(buf);
    n = usb.readBytes(buf, n);
    int m = 0;
    bool diag = false;
    for (int i = 0; i < n; i++) {
      if (buf[i] == DIAG_KEY) diag = true;
      else if (buf[i] == PRESS_SHORT_KEY) press(300);
      else if (buf[i] == PRESS_LONG_KEY) press(6000);
      else if (buf[i] == RELEASE_KEY) release();
      else buf[m++] = buf[i];
    }
    if (m > 0) {
      if (boardOn[idx]) { uart.write(buf, m); txCount[idx] += m; }
      else gatedDrop[idx] += m;
    }
    if (diag) sendStatus(usb);
  }
}

void setup() {
  pinMode(LED_BUILTIN, OUTPUT);
  // Gate the TX pins before the UARTs drive them.
  for (int i = 0; i < 2; i++) {
    gpio_set_oeover(TX_PIN[i], GPIO_OVERRIDE_LOW);
    gpio_disable_pulls(TX_PIN[i]);
  }
  uartJ3.begin(115200);
  uartJ2.begin(115200);

  for (int i = 0; i < 2; i++) {
    gpio_disable_pulls(TX_PIN[i]);
    gpio_pull_down(RX_PIN[i]);        // override the library's pull-up
    lineHigh[i] = gpio_get(RX_PIN[i]);
    lineSince[i] = millis();
    setTx(i, false);
  }

  pinMode(OPTO_PIN, OUTPUT); digitalWrite(OPTO_PIN, LOW);
  analogReadResolution(12);
  for (int i = 0; i < 3; i++) pinMode(ADC_PIN[i], INPUT);

  usbJ2.begin(115200);
  Serial.begin(115200);

  wdReboot = watchdog_caused_reboot();
  watchdog_enable(WDT_MS, true);   // pause_on_debug

  // Re-enumerate so the host sees the second CDC interface.
  if (TinyUSBDevice.mounted()) {
    TinyUSBDevice.detach();
    delay(10);
    TinyUSBDevice.attach();
  }
}

void loop() {
  watchdog_update();
  loops++;
  heartbeat();
  noteReboot(Serial, 0);
  noteReboot(usbJ2, 1);
  powerGate(0);
  powerGate(1);
  panel();
  pump(UART_J3, Serial, 0);
  pump(UART_J2, usbJ2, 1);
}

static void sendStatus(Adafruit_USBD_CDC &usb) {
  char tmp[280];
  uint32_t now = millis();
  unsigned long lps = (now - loopsAt) ? (unsigned long)((uint64_t)(loops - loopsPrev) * 1000 / (now - loopsAt)) : 0;
  loopsPrev = loops; loopsAt = now;
  snprintf(tmp, sizeof(tmp),
           "\r\n#pico t=%lus loops/s=%lu wd=%d r=%d w=%d b=%d led=%d chg=%lu btn=%d | ch0 on=%d rx=%d rxB=%lu drop=%lu txB=%lu gdrop=%lu | ch1 on=%d rx=%d rxB=%lu drop=%lu txB=%lu gdrop=%lu\r\n",
           (unsigned long)(now / 1000), lps, (int)wdReboot, mv[0], mv[1], mv[2], (int)ledLevel, (unsigned long)ledChanges, (int)pressing,
           (int)boardOn[0], (int)gpio_get(RX_PIN[0]), (unsigned long)rxCount[0], (unsigned long)dropCount[0],
           (unsigned long)txCount[0], (unsigned long)gatedDrop[0],
           (int)boardOn[1], (int)gpio_get(RX_PIN[1]), (unsigned long)rxCount[1], (unsigned long)dropCount[1],
           (unsigned long)txCount[1], (unsigned long)gatedDrop[1]);
  usb.write(tmp);
}
