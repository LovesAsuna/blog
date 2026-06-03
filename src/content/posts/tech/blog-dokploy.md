---
title: 博客迁移至 Dokploy
published: 2025-04-13
description: 博客迁移至 Dokploy
image: https://pixiv.nl/125647566.jpg
tags: [blog, dokploy]
category: 技术
draft: false
---

# 博客迁移至 Dokploy

## 背景

本博客最开始用的是 `typecho` 框架并使用的付费的 `handsome` 主题。众所周知 `typecho` 主打的就是轻量，本身功能较少，为了博客的美观和功能的扩展我选择了 `handsome` 了主题。但 `handsome` 自从 2023.8 就没有了更新，几乎就是断更的状态。另外很重要的一点，我本身使用的 `handsome` 也是基于原版进行了二次改造，各种适配的问题也难以得到解决，因此在上个月我就将博客迁移到了更成熟的 `wordpress`。

成熟就意味着资源占用大是不可避免的，博客之前一直白嫖着 fly 的现在已经绝版的 legency 计划(每个月不超过 5 刀直接免费)，所以机器资源一直是1c256m。面对着 `wordpress` 这样的庞然大物确实有点力不从心，打开 `wp-statistics` 甚至会 oom，无奈之下只好对 fly 进行扩容，但发现扩容后的花销非常大，一个月竟高达 15 刀，最后迫不得已买了腾讯云的 2c4g 轻量云。

抛开 fly 的花销，fly 让我第一次接触到了 paas，也见识到了 paas 在部署方面的简单易用性，其几乎就是现在互联网公司服务部署的方案。因此迁移到了腾讯云之后我也在研究如何可以在云服务器中进行 paas 部署，在 v2ex 一番搜索看到了 [Dokploy](https://dokploy.com/zh-Hans "Dokploy")。

## Dokploy

> 部署在任何设施之上，以前所未有的简洁和高效提供一站式项目、数据的管理以及系统监控。

### 安装

一行命令就完成了 `curl -sSL https://dokploy.com/install.sh | sh`，但有一点在安装的时候踩了坑：安装脚本会使用 `ipconfig.me` 检测 ip 地址，如果刚好这个网站挂了，就会表现为在初始化 swarm 时参数错误，具体可以看 [https://github.com/Dokploy/dokploy/issues/1007](https://github.com/Dokploy/dokploy/issues/1007)

### 使用

第一步就是注册一个账号，这一步很简单不过多介绍，接下来的内容都以如何部署我的 `wordpress` 博客为例。

#### 创建项目

我这里创建了一个名为 blog 的项目，它是很多子服务的集合。以 `wordpress` 博客来说，这个项目需要一个 `数据库服务` + `wordpress服务`。

#### 创建服务

##### mariadb

在数据库上我选择了 `mariadb`，将原来的 `mysql` 迁移到了 `mariadb`，这里没什么理由，就是一开始选错了，趁这个机会重新用回 `mariadb`。

`Dokploy` 提供了常用的数据库服务，其中就包含了 `mariadb`：

![数据库模板](https://s2.loli.net/2024/12/29/Bq4NAoUFyekYTtC.png)

![数据库选择](https://s2.loli.net/2024/12/29/qbgGJT3KYsyLzoh.png)

选择 `mariadb` 后有一些要填的基本选项：

1. 数据库名称
2. 非 root 用户名
3. 非 root 用户密码
4. root 密码
5. 镜像(一般不动)

填写完之后就创建好了数据库服务，进入到了管理页面

![数据库管理页面](https://s2.loli.net/2024/12/29/tqbZJO2lIHo53G6.png)

点击 `deploy` 即可进行部署，图上标注了后续会用到的一些参数。

##### wordpress

> 同理创建一个 Application 类型的项目(也可以选择 template 模板，更加方便，但我直接用的 Application)。

博客本体不像数据库那样相对稳定，博客属于一种线上服务，会经常需要升级、添加插件等等，因此 paas 是提供了类似 CI/CD 一样的部署流程，通过集成 git、docker等方式先拉到本体再进行一键化部署，这就需要使用者本身懂得 `Dockerfile` 的编写。

由于之前使用的 fly 也是使用的 `Dockerfile` 来进行部署，迁移到 `Dokploy` 并没有额外的成本，可以直接复用，因此我将 `Github` 作为 `Provider`，`BuildType` 选择 `Dockerfile`。

进行了基本的配置之后，需要对数据库的连接配置做一些修改，因为之前使用了 fly 的内网数据库，迁移之后也要进行调整，为此我删除了 `wp-config.php` 来重新生成对应的数据库配置，地址则为上图的数据库内网地址。

所有的一切都完成之后就可以进行 `deploy` 部署了。最后为了博客能进行访问，需要给应用设置一个可访问域名，在管理页的上方有一个 domain 选项，端口用 80 并配置自动 https，这样外网的所有请求打到 `dokploy` 上时，`dokploy` 就能知道访问的是什么服务，并在后面进行 https 卸载，访问到实际的内部服务时就是纯粹的 http。
