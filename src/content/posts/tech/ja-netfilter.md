---
title: ja-netfilter 如何生成结果值
published: 2025-05-24
description: ja-netfilter 如何生成结果值
image: https://pixiv.nl/129911460.png
tags: [Java, hack, JetBrains]
category: 技术
draft: false
---

> 本为旨在提供一个思路如何在自定义证书的场景计算替换结果值

## 前情提要

由于破解需要生成一个假的子证书，那如何绕过 `JB` 根证书的签名校验就成了关键，这也是 `ja-netfilter` 发挥作用的地方：在证书校验的底层，RSA算法验签时用到的 `pow` 方法，通过 `java agent` 的方式，匹配方法的入参，强制替换出参，那如何计算出参就是本文的重点。

## 计算方法

> 本文采用 golang 语言进行演示，其他语言过程类似

既然想知道如何计算结果，那不妨先看看正常的计算流程是怎样的。以下是一个简单的签名验证流程：

```go
package main

import (
    "crypto/rand"
    "crypto/rsa"
    "crypto/x509"
    "crypto/x509/pkix"
    "fmt"
    "math/big"
    "time"
)

func main() {
    // 生成根证书私钥
    rootKey, err := rsa.GenerateKey(rand.Reader, 512)
    if err != nil {
        panic(err)
    }

    // 创建根证书模板
    rootTemplate := x509.Certificate{
        Subject: pkix.Name{
            CommonName: "Root CA",
        },
        NotBefore:             time.Now(),
        NotAfter:              time.Now().AddDate(1, 0, 0),
        BasicConstraintsValid: true, // 必要，防止自签名证书不通过
        IsCA:                  true, // 必要，防止自签名证书不通过
    }

    // 创建根证书
    rootCertDER, err := x509.CreateCertificate(rand.Reader, &rootTemplate, &rootTemplate, &rootKey.PublicKey, rootKey)
    if err != nil {
        panic(err)
    }

    // 生成子证书私钥
    childKey, err := rsa.GenerateKey(rand.Reader, 512)
    if err != nil {
        panic(err)
    }

    // 创建子证书模板
    childTemplate := x509.Certificate{
        SerialNumber: big.NewInt(2),
        Subject: pkix.Name{
            CommonName: "Child Cert",
        },
        NotBefore: time.Now(),
        NotAfter:  time.Now().AddDate(1, 0, 0),
    }

    // 创建子证书
    childCertDER, err := x509.CreateCertificate(rand.Reader, &childTemplate, &rootTemplate, &childKey.PublicKey, rootKey)
    if err != nil {
        panic(err)
    }

    // 解析根证书
    rootCert, err := x509.ParseCertificate(rootCertDER)
    if err != nil {
        panic(err)
    }

    // 解析子证书
    childCert, err := x509.ParseCertificate(childCertDER)
    if err != nil {
        panic(err)
    }

    // 创建证书池并添加根证书
    roots := x509.NewCertPool()
    roots.AddCert(rootCert)

    // 验证子证书
    opts := x509.VerifyOptions{
        Roots: roots,
    }
    if _, err := childCert.Verify(opts); err != nil {
        panic("子证书验证失败: " + err.Error())
    }

    fmt.Println("子证书验证成功!")
}
```

接着我们可以稍作修改，模拟用 `JB` 根证书验签的过程会发生什么

```go
package main

import (
    "crypto/rand"
    "crypto/rsa"
    "crypto/x509"
    "crypto/x509/pkix"
    "encoding/pem"
    "fmt"
    "math/big"
    "time"
)

const JBROOT = `
-----BEGIN CERTIFICATE-----
MIIFOzCCAyOgAwIBAgIJANJssYOyg3nhMA0GCSqGSIb3DQEBCwUAMBgxFjAUBgNV
BAMMDUpldFByb2ZpbGUgQ0EwHhcNMTUxMDAyMTEwMDU2WhcNNDUxMDI0MTEwMDU2
WjAYMRYwFAYDVQQDDA1KZXRQcm9maWxlIENBMIICIjANBgkqhkiG9w0BAQEFAAOC
Ag8AMIICCgKCAgEA0tQuEA8784NabB1+T2XBhpB+2P1qjewHiSajAV8dfIeWJOYG
y+ShXiuedj8rL8VCdU+yH7Ux/6IvTcT3nwM/E/3rjJIgLnbZNerFm15Eez+XpWBl
m5fDBJhEGhPc89Y31GpTzW0vCLmhJ44XwvYPntWxYISUrqeR3zoUQrCEp1C6mXNX
EpqIGIVbJ6JVa/YI+pwbfuP51o0ZtF2rzvgfPzKtkpYQ7m7KgA8g8ktRXyNrz8bo
iwg7RRPeqs4uL/RK8d2KLpgLqcAB9WDpcEQzPWegbDrFO1F3z4UVNH6hrMfOLGVA
xoiQhNFhZj6RumBXlPS0rmCOCkUkWrDr3l6Z3spUVgoeea+QdX682j6t7JnakaOw
jzwY777SrZoi9mFFpLVhfb4haq4IWyKSHR3/0BlWXgcgI6w6LXm+V+ZgLVDON52F
LcxnfftaBJz2yclEwBohq38rYEpb+28+JBvHJYqcZRaldHYLjjmb8XXvf2MyFeXr
SopYkdzCvzmiEJAewrEbPUaTllogUQmnv7Rv9sZ9jfdJ/cEn8e7GSGjHIbnjV2ZM
Q9vTpWjvsT/cqatbxzdBo/iEg5i9yohOC9aBfpIHPXFw+fEj7VLvktxZY6qThYXR
Rus1WErPgxDzVpNp+4gXovAYOxsZak5oTV74ynv1aQ93HSndGkKUE/qA/JECAwEA
AaOBhzCBhDAdBgNVHQ4EFgQUo562SGdCEjZBvW3gubSgUouX8bMwSAYDVR0jBEEw
P4AUo562SGdCEjZBvW3gubSgUouX8bOhHKQaMBgxFjAUBgNVBAMMDUpldFByb2Zp
bGUgQ0GCCQDSbLGDsoN54TAMBgNVHRMEBTADAQH/MAsGA1UdDwQEAwIBBjANBgkq
hkiG9w0BAQsFAAOCAgEAjrPAZ4xC7sNiSSqh69s3KJD3Ti4etaxcrSnD7r9rJYpK
BMviCKZRKFbLv+iaF5JK5QWuWdlgA37ol7mLeoF7aIA9b60Ag2OpgRICRG79QY7o
uLviF/yRMqm6yno7NYkGLd61e5Huu+BfT459MWG9RVkG/DY0sGfkyTHJS5xrjBV6
hjLG0lf3orwqOlqSNRmhvn9sMzwAP3ILLM5VJC5jNF1zAk0jrqKz64vuA8PLJZlL
S9TZJIYwdesCGfnN2AETvzf3qxLcGTF038zKOHUMnjZuFW1ba/12fDK5GJ4i5y+n
fDWVZVUDYOPUixEZ1cwzmf9Tx3hR8tRjMWQmHixcNC8XEkVfztID5XeHtDeQ+uPk
X+jTDXbRb+77BP6n41briXhm57AwUI3TqqJFvoiFyx5JvVWG3ZqlVaeU/U9e0gxn
8qyR+ZA3BGbtUSDDs8LDnE67URzK+L+q0F2BC758lSPNB2qsJeQ63bYyzf0du3wB
/gb2+xJijAvscU3KgNpkxfGklvJD/oDUIqZQAnNcHe7QEf8iG2WqaMJIyXZlW3me
0rn+cgvxHPt6N4EBh5GgNZR4l0eaFEV+fxVsydOQYo1RIyFMXtafFBqQl6DDxujl
FeU3FZ+Bcp12t7dlM4E0/sS1XdL47CfGVj4Bp+/VbF862HmkAbd7shs7sDQkHbU=
-----END CERTIFICATE-----
`

func main() {
    // 创建JB真实根证书
    JBRootCertBlock, _ := pem.Decode([]byte(JBROOT))
    JBRootCert, err := x509.ParseCertificate(JBRootCertBlock.Bytes)
    if err != nil {
        panic("JBRootCert parse failed")
    }
    // 生成根证书私钥
    rootKey, err := rsa.GenerateKey(rand.Reader, 512)
    if err != nil {
        panic(err)
    }

    // 创建根证书模板
    rootTemplate := x509.Certificate{
        Subject: pkix.Name{
            CommonName: "Root CA",
        },
        NotBefore:             time.Now(),
        NotAfter:              time.Now().AddDate(1, 0, 0),
        BasicConstraintsValid: true, // 必要，防止自签名证书不通过
        IsCA:                  true, // 必要，防止自签名证书不通过
    }

    // 生成子证书私钥
    childKey, err := rsa.GenerateKey(rand.Reader, 512)
    if err != nil {
        panic(err)
    }

    // 创建子证书模板
    childTemplate := x509.Certificate{
        SerialNumber: big.NewInt(2),
        Subject: pkix.Name{
            CommonName: "Child Cert",
        },
        NotBefore: time.Now(),
        NotAfter:  time.Now().AddDate(1, 0, 0),
    }

    // 创建子证书
    childCertDER, err := x509.CreateCertificate(rand.Reader, &childTemplate, &rootTemplate, &childKey.PublicKey, rootKey)
    if err != nil {
        panic(err)
    }

    // 解析子证书
    childCert, err := x509.ParseCertificate(childCertDER)
    childCert.RawIssuer = JBRootCert.RawSubject // 为了通过 golang 底层的 parent 名字校验
    if err != nil {
        panic(err)
    }

    // 创建证书池并添加JB的真实根证书
    roots := x509.NewCertPool()
    roots.AddCert(JBRootCert)
    // 验证子证书
    opts := x509.VerifyOptions{
        Roots: roots,
    }
    if _, err := childCert.Verify(opts); err != nil {
        panic("子证书验证失败: " + err.Error())
    }

    fmt.Println("子证书验证成功!")
}
```

以上的代码会形成一个验签堆栈，具体如下：

![验签](https://s2.loli.net/2025/05/24/veIurpSOcEM9lBR.png)

最终会进到 `rsa.verifyPKCS1v15` 的验签方法，我们要研究的部分就位于此。

```go
func verifyPKCS1v15(pub *PublicKey, hash string, hashed []byte, sig []byte) error {
    if fipsApproved, err := checkPublicKey(pub); err != nil {
        return err
    } else if !fipsApproved {
        fips140.RecordNonApproved()
    }

    // RFC 8017 Section 8.2.2: If the length of the signature S is not k
    // octets (where k is the length in octets of the RSA modulus n), output
    // "invalid signature" and stop.
    if pub.Size() != len(sig) {
        return ErrVerification
    }

    em, err := encrypt(pub, sig)
    if err != nil {
        return ErrVerification
    }

    expected, err := pkcs1v15ConstructEM(pub, hash, hashed)
    if err != nil {
        return ErrVerification
    }
    if !bytes.Equal(em, expected) {
        return ErrVerification
    }

    return nil
}

func encrypt(pub *PublicKey, plaintext []byte) ([]byte, error) {
    m, err := bigmod.NewNat().SetBytes(plaintext, pub.N)
    if err != nil {
        return nil, err
    }
    return bigmod.NewNat().ExpShortVarTime(m, uint(pub.E), pub.N).Bytes(pub.N), nil
}
```

以上是一个RSA验签的实现，具体的原理可以参考[RSA加密&签名](https://www.xuzhengtong.com/2022/07/25/secure/RSA/)，其大致流程为：

1. encrypt

$$  
h_1 = \text{子证书签名}^{\text{根证书的公钥}\ e} \pmod{\text{根证书的公钥}\ N}
$$

2. 根据 PKCS 标准进行填充(为了安全性，标准定义加密的结果不可直接使用，需要进行一个填充)，因为生成签名时也经过了这一个步骤

4. 判断签名 `h2 == h1`

从以上流程我们可以得到几个事实：

1. `hashed(h2)` 结果是错误的，因为是用假的根证书生成的子证书

3. 由于 hashed 是错的，我们的目标就是利用 `ja-netfilter` 来使得 `h1=h2`，能修改的只有 `h1`

5. `ja-netfilter` 作用与 `encrypt` 方法，即匹配 `encrypt` 的所有入参，返回一个固定的结果

基于以上的事实我们就可以得出计算的思路：令 `h1(可通过 ja-netfilter 的配置文件指定)` 等于 `h2(既定事实)` ，问题就变成了如何计算 `h2`，即：

1. 传入 `根证书的公钥、子证书验签算法(该场景固定为 SHA-256)、子证书的 TBS 的 sha256 哈希结果(不可直接用签名，因为已经包含了填充)` 来调用 `pkcs1v15ConstructEM` 方法(实现简单，可以直接复制标准库)，得到的结果即为配置文件的替换结果
