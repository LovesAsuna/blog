---
title: 解决win10应用程序图标丢失问题
published: 2024-12-08
description: 解决win10应用程序图标丢失问题
image: https://ghfast.top/https://raw.githubusercontent.com/Eikanya/live2dCDN/master/img/cover9.jpg
tags: [windows, os, icon]
category: 技术
draft: false
---

# 背景

我的Idea应用程序图标变为白色(不是快捷方式)

# 解决方案:

1. 进入命令提示符
2. 输入以下内容
    
    ```batch
    taskkill /im explorer.exe /f
    cd /d %userprofile%\appdata\local
    del iconcache.db /a
    start explorer.exe
    exit
    ```
    

至此问题应该已经修复
