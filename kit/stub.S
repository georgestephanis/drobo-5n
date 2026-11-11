@ Drobo 5N boot probe / trampoline, run from U-Boot with `go`.
@  -DPROBE : print CPU state, return to U-Boot.
@  default: print CPU state, clean+disable caches/MMU (incl. Aurora L2) if on,
@            then jump to the zImage(+DTB) appended at `payload` with
@            r0=0, r1=~0, r2=0 (kernel uses the appended DTB, /chosen for args).
	.syntax unified
	.arm
	.text
	.global _start
	.macro mk c
	ldr	r4, =0xd0012000
	mov	r1, #\c
	bl	putc
	.endm
	@ t600 c: print "[c", read 0x600000 ("r"), write it back ("w"), "]".
	@ Clobbers r0, r1, r3, r4 (left = UART), r10. Built only with -DT600.
	.macro t600 c
#ifdef T600
	ldr	r4, =0xd0012000
	mov	r1, #'['
	bl	putc
	mov	r1, #\c
	bl	putc
	ldr	r10, =0x00600000
	ldr	r0, [r10]
	mov	r1, #'r'
	bl	putc
	str	r0, [r10]
	dsb
	mov	r1, #'w'
	bl	putc
	mov	r1, #']'
	bl	putc
#endif
	.endm
_start:
	push	{r4-r12, lr}
	mov	r9, r0
	mov	r10, r1
	mov	r11, r2
	mrs	r12, cpsr
	ldr	r4, =0xd0012000
	adrl	r0, s_hdr
	bl	puts
	mov	r0, r9
	bl	puthex
	adrl	r0, s_r1
	bl	puts
	mov	r0, r10
	bl	puthex
	adrl	r0, s_r2
	bl	puts
	mov	r0, r11
	bl	puthex
	adrl	r0, s_cpsr
	bl	puts
	mov	r0, r12
	bl	puthex
	adrl	r0, s_sctlr
	bl	puts
	mrc	p15, 0, r0, c1, c0, 0
	bl	puthex
	adrl	r0, s_mpidr
	bl	puts
	mrc	p15, 0, r0, c0, c0, 5
	bl	puthex
	adrl	r0, s_l2
	bl	puts
	ldr	r0, =0xd0008100
	ldr	r0, [r0]
	bl	puthex
	adrl	r0, s_irb
	bl	puts
	ldr	r0, =0xd0020080
	ldr	r0, [r0]
	bl	puthex
	adrl	r0, s_pc
	bl	puts
	adr	r0, _start
	bl	puthex
	adrl	r0, s_nl
	bl	puts
#ifdef DBGREGS
	@ Debug registers (CP14): DIDR, DSCR, then BVR/BCR 0-5, WVR/WCR 0-3.
	adrl	r0, s_dbg
	bl	puts
	mrc	p14, 0, r0, c0, c0, 0	@ DBGDIDR
	bl	puthex
	mov	r1, #' '
	bl	putc
	mrc	p14, 0, r0, c0, c1, 0	@ DBGDSCRint
	bl	puthex
	adrl	r0, s_bp
	bl	puts
	.irp n, 0,1,2,3,4,5
	mrc	p14, 0, r0, c0, c\n, 4
	bl	puthex
	mov	r1, #'/'
	bl	putc
	mrc	p14, 0, r0, c0, c\n, 5
	bl	puthex
	mov	r1, #' '
	bl	putc
	.endr
	adrl	r0, s_wp
	bl	puts
	.irp n, 0,1,2,3
	mrc	p14, 0, r0, c0, c\n, 6
	bl	puthex
	mov	r1, #'/'
	bl	putc
	mrc	p14, 0, r0, c0, c\n, 7
	bl	puthex
	mov	r1, #' '
	bl	putc
	.endr
	adrl	r0, s_nl
	bl	puts
#endif
#ifdef PROBE
	pop	{r4-r12, pc}
#else
#ifdef T6B
	@ Separate read, nearby write, and the 0x600000 write, then dots.
	ldr	r4, =0xd0012000
	mov	r1, #'R'
	bl	putc
	ldr	r10, =0x00600000
	ldr	r0, [r10]
	bl	puthex
	mov	r1, #' '
	bl	putc
	ldr	r0, [r10, #4]
	bl	puthex
	mov	r1, #' '
	bl	putc
	ldr	r10, =0x00600100
	ldr	r0, [r10]
	str	r0, [r10]
	dsb
	mov	r1, #'Q'
	bl	putc
	ldr	r10, =0x00600000
	ldr	r0, [r10]
	str	r0, [r10]
	dsb
	mov	r1, #'W'
	bl	putc
	mov	r9, #40
94:	ldr	r2, =0x200000
95:	subs	r2, r2, #1
	bne	95b
	mov	r1, #'.'
	bl	putc
	subs	r9, r9, #1
	bne	94b
	mov	r1, #'|'
	bl	putc
#endif
	t600	'a'
	adrl	r0, s_go
	bl	puts
	cpsid	aif
	@ wait for UART to drain
3:	ldr	r3, [r4, #0x14]
	tst	r3, #0x40		@ TEMT
	beq	3b
	t600	'b'
	mk	'1'
	bl	v7_flush_dcache_all
	mk	'2'
	t600	'c'
	@ Aurora L2: if enabled, clean+invalidate all ways, then disable
	ldr	r0, =0xd0008100
	ldr	r1, [r0]
	tst	r1, #1
	beq	4f
	ldr	r2, =0xd00087fc		@ flush (clean+inv) by way
	ldr	r3, =0xffff
	str	r3, [r2]
5:	ldr	r3, [r2]
	cmp	r3, #0
	bne	5b
	ldr	r2, =0xd0008700		@ cache sync
	mov	r3, #0
	str	r3, [r2]
	bic	r1, r1, #1
	str	r1, [r0]
	dsb
4:	mk	'3'
	mrc	p15, 0, r0, c1, c0, 0
	bic	r0, r0, #0x0005		@ M, C
	bic	r0, r0, #0x1000		@ I
	mcr	p15, 0, r0, c1, c0, 0
	isb
	mk	'4'
	t600	'd'
	mov	r0, #0
	mcr	p15, 0, r0, c7, c5, 0	@ ICIALLU
	mcr	p15, 0, r0, c8, c7, 0	@ TLBIALL
	mcr	p15, 0, r0, c7, c5, 6	@ BPIALL
	dsb
	isb
	mk	'5'
	t600	'e'
	@ Park CPUs 1-3: U-Boot left them spinning inside its own code (0x600000+),
	@ which the kernel copy/decompress overwrites. Print, then assert reset.
	adrl	r0, s_rst
	bl	puts
	ldr	r10, =0xd0020800
	mov	r9, #0
10:	ldr	r0, [r10, r9]
	bl	puthex
	mov	r1, #' '
	bl	putc
	add	r9, r9, #8
	cmp	r9, #0x20
	blt	10b
	ldr	r10, =0xd0020800
	mov	r0, #1
	str	r0, [r10, #0x08]
	str	r0, [r10, #0x10]
	str	r0, [r10, #0x18]
	dsb
	adrl	r0, s_rst2
	bl	puts
	mov	r9, #0
11:	ldr	r0, [r10, r9]
	bl	puthex
	mov	r1, #' '
	bl	putc
	add	r9, r9, #8
	cmp	r9, #0x20
	blt	11b
	adrl	r0, s_nl
	bl	puts
	@ Own exception vectors (VBAR) so a fault prints instead of jumping into
	@ U-Boot's handlers, which the copy overwrites.
	t600	'f'
	adrl	r0, s_vbar
	bl	puts
	mrc	p15, 0, r0, c12, c0, 0
	bl	puthex
	adrl	r0, vectors
	mcr	p15, 0, r0, c12, c0, 0
	isb
	adrl	r0, s_nl
	bl	puts
	@ Halt the three EHCI controllers U-Boot left running (USBCMD.RS=0),
	@ and wait for USBSTS.HCHalted.
	ldr	r10, =0xd0050140
	bl	ehci_halt
	ldr	r10, =0xd0051140
	bl	ehci_halt
	ldr	r10, =0xd0052140
	bl	ehci_halt
	adrl	r0, s_usb
	bl	puts
	t600	'h'
#ifdef COPY
#ifdef POKE
	@ Read-and-write-back test at addresses around U-Boot's stack and code,
	@ to tell an address-specific hang from a copy/time one.
	adrl	r9, poke_tab
91:	ldr	r10, [r9], #4
	cmp	r10, #0
	beq	92f
	mov	r1, #'P'
	bl	putc
	mov	r0, r10
	bl	puthex
	ldr	r0, [r10]
	str	r0, [r10]
	dsb
	mov	r1, #'+'
	bl	putc
	mov	r1, #' '
	bl	putc
	b	91b
92:	adrl	r0, s_nl
	bl	puts
#endif
	@ copy uncompressed Image (payload, IMG_LEN bytes) to 0x8000, r2 = DTB
	adrl	r5, payload
	ldr	r6, =DEST
	ldr	r7, img_len		@ patched in by mkdrobokernel (see DRBOLEN0)
	ldr	r4, =0xd0012000
7:	ldr	r3, [r5], #4
	str	r3, [r6], #4
	subs	r7, r7, #4
	ble	71f
	cmp	r6, #0x5c0000		@ fine-grained (4 KB) progress around
	blo	72f			@ the region where the copy hung
	cmp	r6, #0x700000
	bhs	72f
	movs	r3, r7, lsl #20
	bne	7b
	b	73f
72:	movs	r3, r7, lsl #14		@ every 256 KB remaining
	bne	7b
73:
	mov	r11, r5		@ puthex/puts clobber r5/r6 (our src/dst);
	mov	r12, r6		@ the stack is inside the copy range, so use r11/r12
	mov	r1, #' '
	bl	putc
	mov	r0, r12
	bl	puthex
	mov	r5, r11
	mov	r6, r12
	ldr	r4, =0xd0012000
	b	7b
71:
	ldr	r4, =0xd0012000
	adrl	r0, s_cp
	bl	puts
	ldr	r0, =DEST
	ldr	r0, [r0]
	bl	puthex
	mov	r1, #' '
	bl	putc
	adrl	r0, payload
	ldr	r0, [r0]
	bl	puthex
	mov	r1, #' '
	bl	putc
	ldr	r0, =DTB_ADDR
	ldr	r0, [r0]
	bl	puthex
	adrl	r0, s_nl
	bl	puts
8:	ldr	r3, [r4, #0x14]
	tst	r3, #0x40
	beq	8b
	mov	r0, #0
	mcr	p15, 0, r0, c7, c5, 0	@ ICIALLU: the kernel was just written
	mcr	p15, 0, r0, c7, c5, 6	@ BPIALL
	dsb
	isb
	mvn	r1, #0
	ldr	r2, =DTB_ADDR
	ldr	r3, =DEST
	bx	r3
#else
	mov	r0, #0
	mvn	r1, #0
	mov	r2, #0
	b	payload
#endif

v7_flush_dcache_all:
	mrc	p15, 1, r0, c0, c0, 1	@ CLIDR
	ands	r3, r0, #0x7000000
	mov	r3, r3, lsr #23
	beq	9f
	mov	r10, #0
1:	add	r2, r10, r10, lsr #1
	mov	r1, r0, lsr r2
	and	r1, r1, #7
	cmp	r1, #2
	blt	8f
	mcr	p15, 2, r10, c0, c0, 0
	isb
	mrc	p15, 1, r1, c0, c0, 0
	and	r2, r1, #7
	add	r2, r2, #4
	ldr	r4, =0x3ff
	ands	r4, r4, r1, lsr #3
	clz	r5, r4
	ldr	r7, =0x7fff
	ands	r7, r7, r1, lsr #13
2:	mov	r9, r4
6:	orr	r11, r10, r9, lsl r5
	orr	r11, r11, r7, lsl r2
	mcr	p15, 0, r11, c7, c14, 2
	subs	r9, r9, #1
	bge	6b
	subs	r7, r7, #1
	bge	2b
8:	add	r10, r10, #2
	cmp	r3, r10
	bgt	1b
9:	mov	r10, #0
	mcr	p15, 2, r10, c0, c0, 0
	dsb
	isb
	bx	lr
#endif

	.balign	32
vectors:
	b	.		@ reset
	b	exc1
	b	exc2
	b	exc3
	b	exc4
	b	exc5
	b	exc6
	b	exc7
exc1:	mov	r9, #1
	b	exc
exc2:	mov	r9, #2
	b	exc
exc3:	mov	r9, #3
	b	exc
exc4:	mov	r9, #4
	b	exc
exc5:	mov	r9, #5
	b	exc
exc6:	mov	r9, #6
	b	exc
exc7:	mov	r9, #7
	b	exc
exc:	mov	r10, lr
	mrs	r11, spsr
	ldr	r4, =0xd0012000
	adrl	r0, s_exc
	bl	puts
	mov	r0, r9
	bl	puthex
	adrl	r0, s_lr
	bl	puts
	mov	r0, r10
	bl	puthex
	adrl	r0, s_spsr
	bl	puts
	mov	r0, r11
	bl	puthex
	adrl	r0, s_dfsr
	bl	puts
	mrc	p15, 0, r0, c5, c0, 0
	bl	puthex
	adrl	r0, s_dfar
	bl	puts
	mrc	p15, 0, r0, c6, c0, 0
	bl	puthex
	adrl	r0, s_ifsr
	bl	puts
	mrc	p15, 0, r0, c5, c0, 1
	bl	puthex
	adrl	r0, s_nl
	bl	puts
	b	.

ehci_halt:
	ldr	r0, [r10]		@ USBCMD
	bic	r0, r0, #0x31		@ RS, periodic, async schedule enable
	str	r0, [r10]
	ldr	r2, =0x100000
1:	ldr	r0, [r10, #4]		@ USBSTS
	tst	r0, #0x1000		@ HCHalted
	bxne	lr
	subs	r2, r2, #1
	bne	1b
	bx	lr

putc:	ldr	r3, [r4, #0x14]
	tst	r3, #0x20
	beq	putc
	str	r1, [r4]
	bx	lr
puts:	mov	r8, lr
	mov	r5, r0
1:	ldrb	r1, [r5], #1
	cmp	r1, #0
	beq	2f
	bl	putc
	b	1b
2:	bx	r8
puthex:	mov	r8, lr
	mov	r5, r0
	mov	r6, #28
1:	lsr	r1, r5, r6
	and	r1, r1, #0xf
	cmp	r1, #10
	addlt	r1, r1, #'0'
	addge	r1, r1, #('a'-10)
	bl	putc
	subs	r6, r6, #4
	bge	1b
	bx	r8

	.ltorg
#ifdef POKE
	.balign	4
poke_tab: .word 0x00200000, 0x005c0000, 0x005ff000, 0x005fff00, 0x00600000, 0x00601000, 0x00680000, 0x006c0000, 0x00700000, 0x00800000, 0
#endif
s_dbg:	.asciz	"#STUB dbg didr/dscr="
s_bp:	.asciz	" bvr/bcr: "
s_wp:	.asciz	" wvr/wcr: "
s_hdr:	.asciz	"\r\n#STUB alive r0="
s_r1:	.asciz	" r1="
s_r2:	.asciz	" r2="
s_cpsr:	.asciz	" cpsr="
s_sctlr: .asciz	" sctlr="
s_mpidr: .asciz	" mpidr="
s_l2:	.asciz	" l2ctl="
s_irb:	.asciz	" intregwin="
s_pc:	.asciz	" pc="
s_nl:	.asciz	"\r\n"
s_go:	.asciz	"#STUB caches off, jumping\r\n"
s_rst:	.asciz	"\r\n#STUB cpu reset regs: "
s_rst2:	.asciz	" -> parked: "
s_vbar:	.asciz	"#STUB vbar was "
s_exc:	.asciz	"\r\n#STUB EXCEPTION vec="
s_lr:	.asciz	" lr="
s_spsr:	.asciz	" spsr="
s_dfsr:	.asciz	" dfsr="
s_dfar:	.asciz	" dfar="
s_ifsr:	.asciz	" ifsr="
s_usb:	.asciz	"#STUB ehci halted\r\n"
s_cp:	.asciz	"#STUB copied: dest[0] src[0] dtb[0] = "
	.balign	4
	.ascii	"DRBOLEN0"		@ marker: the next word is the Image length
img_len:	.word	IMG_LEN
	.balign	256
payload:
