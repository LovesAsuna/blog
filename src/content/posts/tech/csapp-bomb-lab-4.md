---
title: CSAPP Bomb Lab 4
published: 2024-12-18
description: CSAPP Bomb Lab 4
image: https://ghfast.top/https://raw.githubusercontent.com/xiyan520/tp/master/pc/111.jpg
tags: [CSAPP]
category: 技术
draft: false
---

# Phase\_4

## 汇编

### phase\_4

```asm
0x000000000040100c <+0>: sub $0x18,%rsp 0x0000000000401010 <+4>: lea 0xc(%rsp),%rcx 0x0000000000401015 <+9>: lea 0x8(%rsp),%rdx 0x000000000040101a <+14>: mov $0x4025cf,%esi 0x000000000040101f <+19>: mov $0x0,%eax 0x0000000000401024 <+24>: call 0x400bf0 [\_\_isoc99\_sscanf@plt](mailto:__isoc99_sscanf@plt) 0x0000000000401029 <+29>: cmp $0x2,%eax => 0x000000000040102c <+32>: jne 0x401035 <phase\_4+41> 0x000000000040102e <+34>: cmpl $0xe,0x8(%rsp) 0x0000000000401033 <+39>: jbe 0x40103a <phase\_4+46> 0x0000000000401035 <+41>: call 0x40143a 0x000000000040103a <+46>: mov $0xe,%edx 0x000000000040103f <+51>: mov $0x0,%esi 0x0000000000401044 <+56>: mov 0x8(%rsp),%edi 0x0000000000401048 <+60>: call 0x400fce 0x000000000040104d <+65>: test %eax,%eax 0x000000000040104f <+67>: jne 0x401058 <phase\_4+76> 0x0000000000401051 <+69>: cmpl $0x0,0xc(%rsp) 0x0000000000401056 <+74>: je 0x40105d <phase\_4+81> 0x0000000000401058 <+76>: call 0x40143a 0x000000000040105d <+81>: add $0x18,%rsp 0x0000000000401061 <+85>: ret
```

### func4

```asm
0x0000000000400fce <+0>: sub $0x8,%rsp 0x0000000000400fd2 <+4>: mov %edx,%eax 0x0000000000400fd4 <+6>: sub %esi,%eax 0x0000000000400fd6 <+8>: mov %eax,%ecx 0x0000000000400fd8 <+10>: shr $0x1f,%ecx 0x0000000000400fdb <+13>: add %ecx,%eax 0x0000000000400fdd <+15>: sar %eax 0x0000000000400fdf <+17>: lea (%rax,%rsi,1),%ecx 0x0000000000400fe2 <+20>: cmp %edi,%ecx 0x0000000000400fe4 <+22>: jle 0x400ff2 <func4+36> 0x0000000000400fe6 <+24>: lea -0x1(%rcx),%edx 0x0000000000400fe9 <+27>: call 0x400fce 0x0000000000400fee <+32>: add %eax,%eax 0x0000000000400ff0 <+34>: jmp 0x401007 <func4+57> 0x0000000000400ff2 <+36>: mov $0x0,%eax 0x0000000000400ff7 <+41>: cmp %edi,%ecx 0x0000000000400ff9 <+43>: jge 0x401007 <func4+57> 0x0000000000400ffb <+45>: lea 0x1(%rcx),%esi 0x0000000000400ffe <+48>: call 0x400fce 0x0000000000401003 <+53>: lea 0x1(%rax,%rax,1),%eax 0x0000000000401007 <+57>: add $0x8,%rsp 0x000000000040100b <+61>: ret
```

## 调用流程

```text
1. phase\_4 -> func4 ($rdi=输入的第一个值; $rsi=0; $rdx=14(0xe))
2. $eax = $edx; $eax -= $rsi; $ecx = $eax; $ecx = $ecx >>> 31(逻辑右移获取符号位); $eax += $ecx; $eax >>= 1(算数右移); $ecx = $rax + $rsi
    1. $ecx = ($edx - $rsi) >>> 31; $eax = (($edx - $rsi) + $ecx) >> 1; $ecx = $rax + $rsi
3. if $ecx <= $edi (有符号比较)
    1. $eax = 0;
    2. if $ecx >= $edi (有符号比较)
        1. 直接返回$eax
    3. $esi = $rcx + 1
    4. 递归调用func4
    5. return $rax + $rax + 1
4. $edx = $rcx - 1
5. 递归调用func4
```

## 还原C代码

> 尝试将以上代码还原成原始的C语言代码

```c
void phase_4() {
    z = 14;
    // x指代第一个输入参数，y指代第二个输入参数
    int res = func4(x, 0);
    if (res != 0) {
        return;
    }
    if (y == 0) {
        return 0  ;
    }
}

int z;
// a = $rax($eax)
// b = $rcx($ecx)
// z = $rdx($edx)
// x = $rdi
// y = $rsi
int func4(int x, int y) {
    int a = z;
    a -= y;
    int b = a;
    b = b >> 31;
    a += b;
    a /= 2;
    b = a + y;
    if (b <= x) {
        a = 0;
        if (b >= x) {
            return a;
        }
        y = b + 1;
        func4(x, y);
        return 2 * a + 1;
    }
    z = b - 1;
    func4(x, y);
    return a;
}
```

可以看出只要精确的控制第一个参数为7，即可将func4中的a赋值为0并返回
