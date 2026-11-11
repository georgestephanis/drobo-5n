#!/usr/bin/env python3
"""make-boot-files.py VMLINUZ INITRD [DTS] : build the three files U-Boot needs for a `go` boot of a
Debian armmp kernel on the Drobo 5N, into ./out:

  out/kernel.bin   stub.bin + the uncompressed Image, with the Image length patched in after DRBOLEN0.
                   Load at 0x8000000 and `go 8000000`. The stub copies the Image to 0x1208000.
  out/initrd       a copy of INITRD. Load at 0x4000000.
  out/drobo5n.dtb  DTS (default drobo5n-base.dts) compiled with /chosen linux,initrd-end set. Load at 0x3f00000.

Needs Python 3 and dtc (macOS: brew install dtc; Debian: apt install device-tree-compiler).
Run it from the kit directory (it reads stub.bin from there).
"""
import lzma, os, shutil, struct, subprocess, sys

here = os.path.dirname(os.path.abspath(__file__))
if len(sys.argv) < 3:
    sys.exit(__doc__)
vmlinuz, initrd = sys.argv[1], sys.argv[2]
dts = sys.argv[3] if len(sys.argv) > 3 else os.path.join(here, "drobo5n-base.dts")
INITRD_AT = 0x4000000
os.makedirs("out", exist_ok=True)

# 1. The zImage carries one xz stream; the magic can also appear by chance, so try each match.
z = open(vmlinuz, "rb").read()
image, i = None, z.find(b"\xfd7zXZ")
while i >= 0 and image is None:
    try:
        image = lzma.LZMADecompressor().decompress(z[i:])
    except lzma.LZMAError:
        i = z.find(b"\xfd7zXZ", i + 1)
if not image or len(image) < 4_000_000:
    sys.exit("no xz-compressed Image found in " + vmlinuz)

# 2. stub + Image.
stub = bytearray(open(os.path.join(here, "stub.bin"), "rb").read())
m = stub.find(b"DRBOLEN0")
if m < 0:
    sys.exit("stub.bin has no DRBOLEN0 marker")
stub[m + 8:m + 12] = struct.pack("<I", len(image))
open("out/kernel.bin", "wb").write(bytes(stub) + image)

# 3. initrd and DTB.
size = os.path.getsize(initrd)
if INITRD_AT + size >= 0x8000000:
    sys.exit("initrd too big: it must end below 0x8000000 (where kernel.bin loads)")
shutil.copyfile(initrd, "out/initrd")
subprocess.run(["dtc", "-q", "-I", "dts", "-O", "dtb", "-o", "out/drobo5n.dtb", dts], check=True)
subprocess.run(["fdtput", "-t", "x", "out/drobo5n.dtb", "/chosen", "linux,initrd-start", "%x" % INITRD_AT], check=True)
subprocess.run(["fdtput", "-t", "x", "out/drobo5n.dtb", "/chosen", "linux,initrd-end", "%x" % (INITRD_AT + size)], check=True)

print("out/kernel.bin  %d bytes (Image %d)" % (len(stub) + len(image), len(image)))
print("out/initrd      %d bytes" % size)
print("out/drobo5n.dtb initrd 0x%x-0x%x" % (INITRD_AT, INITRD_AT + size))
