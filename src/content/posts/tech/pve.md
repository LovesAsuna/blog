---
title: 软路由之 PVE 安装配置
published: 2025-01-21
description: 软路由之 PVE 安装配置
image: https://pixiv.nl/114903143.jpg
tags: [pve]
category: 技术
draft: false
---

# 软路由之 PVE 安装配置

# 背景

最近入手了一个 `N100` 来当软路由玩，如果只是用来当软路由肯定是严重的性能溢出。为了充分发挥 `N100` 的性能，有必要在上面做虚拟化，安装多个系统，目前的计划是先安装一个 `OpenWrt` 做软路由，再安装一个 `Debian` 做服务器。

# 虚拟系统选型

虚拟化依靠底层的操作系统来实现，目前可供我选择的主要有 `ESXI` 和 `PVE`。它们具体的区别如下：

- `ESXi` 是由 `VMware` 公司提供的一种商业虚拟化软件，它是一种基于 `Type-1 Hypervisor` 的虚拟化技术，也就是说它直接运行在物理机上，并管理和控制物理机上的虚拟机。`ESXi` 提供了丰富的管理工具和 `API`，可以方便地管理和监控虚拟机，并且支持 `Live Migration` 功能，可以在不中断服务的情况下迁移虚拟机

- Proxmox VE (PVE)是一种开源的虚拟化平台，基于 `KVM` 和 `LXC` 技术，它运行在物理机上的操作系统之上，并管理和控制虚拟机。`PVE` 提供了基于 `web` 的管理界面和命令行工具，并且支持 `Live Migration` 和 `HA` 功能。`PVE` 支持 `KVM` 虚拟化和 `LXC` 容器虚拟化

# 系统安装

## 3.1 前置准备

> `PVE` 使用 `iso` 镜像的方式来安装，因此就需要一个可以用来引导 iso 安装的环境，也称为 U 盘启动，这里选择用 `Ventoy`(真好用)

1. PVE：`iso` 镜像可在 PVE [官网](https://pve.proxmox.com/wiki/Downloads)下载

3. Ventoy：Ventoy 本体可在[官网](https://www.ventoy.net/en/download.html)下载，然后将其安装到 U 盘上，并把 `PVE` 的 `iso` 镜像放入 U 盘

## 3.2 引导启动

在 `Ventoy` 的引导界面中选择 `PVE` 的图形安装即可，后续安装其实没啥可说的，安装官网的[安装教程](https://pve.proxmox.com/wiki/Installation)来就好。

唯一值得注意的是要记住在配置网络的时候要记住这里配置的 `IP` 地址

![启动界面](https://gh-proxy.com/https://raw.githubusercontent.com/LovesAsuna/BlogCDN/main/PVE/Yapdb7C9koaCnVxigqmcMQIUnud.png)

# 系统配置

`PVE` 安装好后，将自己的电脑用网线连接 `PVE` 的宿主机，并在浏览器中输入上面配置的 `IP` 地址 + 端口号 `8006`，进入 `PVE` 的管理页面。

## 4.1 更新软件源

在管理页面中选择自己安装系统时新创建的节点，并进入 `shell` 中，按照清华软件源的指引进行替换：

- Debian(`PVE` 基于 `Debian`)： [https://mirrors.tuna.tsinghua.edu.cn/help/debian/](https://mirrors.tuna.tsinghua.edu.cn/help/debian/)

- PVE：[https://mirrors.tuna.tsinghua.edu.cn/help/proxmox/](https://mirrors.tuna.tsinghua.edu.cn/help/proxmox/)

完成替换后输入命令 `apt update && apt upgrade -y` 进行软件的更新

## 4.2 配置网桥

我觉得 `PVE` 其他的可以先不配置，网络是最先要配置。由于不考虑极限性能，所以我不打算采用网卡直通的做法。

我的 `N100` 上有四个网卡，因此我会在每个网卡上都映射一个网桥来进行桥接。

![配置网桥](https://gh-proxy.com/https://raw.githubusercontent.com/LovesAsuna/BlogCDN/main/PVE/GnWcbx9ZQoqjx2xIzMyc9UR5nQb.png)

`PVE` 在安装时会默认创建一个网桥，映射到第一个物理网卡上，浏览器所输入的静态地址也是设置在此网桥上。我们可以照猫画虎，创建出另外三个网桥

至此，`PVE` 的基本配置就完成了
