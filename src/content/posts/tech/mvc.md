---
title: "MVC架构与三层架构的联系与区别"
published: 2024-12-14
description: MVC架构与三层架构的联系与区别
image: https://ghfast.top/https://github.com/xiyan520/tp/raw/master/pc/54.jpg
tags: [mvc]
category: 技术
draft: false
---

## 共同点

三层架构与MVC设计模式的设计目标是一致的，都是为了解耦合、提高代码体用率

## 区别

两种架构对项目的结构的理解划分有细微的不同

### 三层架构

首先层分为:

- 表示层(USL--User Show Layer)
  - 平常也可称为View视图层，但与MVC中的View是有区别的
- 业务逻辑层(BLL--Business Logic Layer)
  - 也可称为Service层
- 数据访问层(DAL--Data Access Layer)
  - 也可称为DAO层

* * *

其中三层中的表示层又可分为前台和后台。前台对应于Jsp，html和css而后台对应于Servlet，即前台完成了MVC中的视图V的功能而后台完成了MVC中的控制器C的功能

用户通过点击视图界面，控制器将请求转发到模型中，因此模型之中会有专门的业务处理逻辑，而这部分对应于三层中的业务逻辑层

业务中对数据进行的增删改查部分则对应于三层中的数据访问层

由于进行增删改查的数据往往不是单个的，而是涉及到多种数据，因此往往会把这些数据封装到一个实体类，三层之间即通过这个数据类进行交互，但划分上这个实体类又属于MVC中的模型大类

* * *

三层的流程图大致如下

![三层](https://ghfast.top/https://github.com/LovesAsuna/BlogCDN/blob/main/MVC/mvc.5c2uij6e2300.png)

两者的联系

![两者联系](https://ghfast.top/https://github.com/LovesAsuna/BlogCDN/blob/main/MVC/UX7~B23T66J3C$VKV7CM@SW.4nvqirehoge0.png)
