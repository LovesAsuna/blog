---
title: UML类图表示法
published: 2024-12-06
description: UML类图表示法
image: https://pixiv.nl/73958722.jpg
tags: [UML, software design]
category: 技术
draft: false
---

# 类图表示法

## 类的表示方式

在UML类图中，类使用包含类名、属性(field) 和方法(method) 且带有分割线的矩形来表示，比如下图表示一个Employee类，它包含name,age和address这3个属性，以及work()方法。

![](G:\临时\img\Employee.jpg)

属性/方法名称前加的加号和减号表示了这个属性/方法的可见性，UML类图中表示可见性的符号有三种：

- +：表示public
- \-：表示private
- #：表示protected

属性的完整表示方式是： **可见性 名称 ：类型 \[ = 缺省值\]**

方法的完整表示方式是： **可见性 名称(参数列表) \[ ： 返回类型\]**

> 注意：
>
> ```
> 1，中括号中的内容表示是可选的
> ```
>
> ```
> 2，也有将类型放在变量名前面，返回值类型放在方法名前面
> ```

**举个栗子：**

![](https://ghfast.top/https://github.com/LovesAsuna/BlogCDN/blob/main/UML/demo.4p7zf44kp9c0.png)

上图Demo类定义了三个方法：

- method()方法：修饰符为public，没有参数，没有返回值。
- method1()方法：修饰符为private，没有参数，返回值类型为String。
- method2()方法：修饰符为protected，接收两个参数，第一个参数类型为int，第二个参数类型为String，返回值类型是int。

## 类与类之间关系的表示方式

### 关联关系

关联关系是对象之间的一种引用关系，用于表示一类对象与另一类对象之间的联系，如老师和学生、师傅和徒弟、丈夫和妻子等。关联关系是类与类之间最常用的一种关系，分为一般关联关系、聚合关系和组合关系。我们先介绍一般关联。

关联又可以分为单向关联，双向关联，自关联。

**1，单向关联**

![](https://ghfast.top/https://github.com/LovesAsuna/BlogCDN/blob/main/DesignPattern/UML/customer_address.591jw2isr980.png)

在UML类图中单向关联用一个带箭头的实线表示。上图表示每个顾客都有一个地址，这通过让Customer类持有一个类型为Address的成员变量类实现。

**2，双向关联**

![](https://ghfast.top/https://github.com/LovesAsuna/BlogCDN/blob/main/DesignPattern/UML/customer_product.4xlvhdoiyzg0.png)

从上图中我们很容易看出，所谓的双向关联就是双方各自持有对方类型的成员变量。

在UML类图中，双向关联用一个不带箭头的直线表示。上图中在Customer类中维护一个List\\，表示一个顾客可以购买多个商品；在Product类中维护一个Customer类型的成员变量表示这个产品被哪个顾客所购买。

**3，自关联**

![](https://ghfast.top/https://github.com/LovesAsuna/BlogCDN/blob/main/DesignPattern/UML/node.71km3g94oj40.png)

自关联在UML类图中用一个带有箭头且指向自身的线表示。上图的意思就是Node类包含类型为Node的成员变量，也就是“自己包含自己”。

### 聚合关系

聚合关系是关联关系的一种，是强关联关系，是整体和部分之间的关系。

聚合关系也是通过成员对象来实现的，其中成员对象是整体对象的一部分，但是成员对象可以脱离整体对象而独立存在。例如，学校与老师的关系，学校包含老师，但如果学校停办了，老师依然存在。

在 UML 类图中，聚合关系可以用带空心菱形的实线来表示，菱形指向整体。下图所示是大学和教师的关系图：

![](https://ghfast.top/https://github.com/LovesAsuna/BlogCDN/blob/main/DesignPattern/UML/image-20191229173422328.5vmgyuxcqoo0.png)

### 组合关系

组合表示类之间的整体与部分的关系，但它是一种更强烈的聚合关系。

在组合关系中，整体对象可以控制部分对象的生命周期，一旦整体对象不存在，部分对象也将不存在，部分对象不能脱离整体对象而存在。例如，头和嘴的关系，没有了头，嘴也就不存在了。

在 UML 类图中，组合关系用带实心菱形的实线来表示，菱形指向整体。下图所示是头和嘴的关系图：

![](https://ghfast.top/https://github.com/LovesAsuna/BlogCDN/blob/main/DesignPattern/UML/image-20191229173455149.7g58a2cnuwg0.png)

### 依赖关系

依赖关系是一种使用关系，它是对象之间耦合度最弱的一种关联方式，是临时性的关联。在代码中，某个类的方法通过局部变量、方法的参数或者对静态方法的调用来访问另一个类（被依赖类）中的某些方法来完成一些职责。

在 UML 类图中，依赖关系使用带箭头的虚线来表示，箭头从使用类指向被依赖的类。下图所示是司机和汽车的关系图，司机驾驶汽车：

![](https://ghfast.top/https://github.com/LovesAsuna/BlogCDN/blob/main/DesignPattern/UML/image-20191229173518926.6iwautaa1wg0.png)

### 继承关系

继承关系是对象之间耦合度最大的一种关系，表示一般与特殊的关系，是父类与子类之间的关系，是一种继承关系。

在 UML 类图中，泛化关系用带空心三角箭头的实线来表示，箭头从子类指向父类。在代码实现时，使用面向对象的继承机制来实现泛化关系。例如，Student 类和 Teacher 类都是 Person 类的子类，其类图如下图所示：

![](https://ghfast.top/https://github.com/LovesAsuna/BlogCDN/blob/main/DesignPattern/UML/image-20191229173539838.6ggdfnx3tos0.png)

### 实现关系

实现关系是接口与实现类之间的关系。在这种关系中，类实现了接口，类中的操作实现了接口中所声明的所有的抽象操作。

在 UML 类图中，实现关系使用带空心三角箭头的虚线来表示，箭头从实现类指向接口。例如，汽车和船实现了交通工具，其类图如图 9 所示。

![](https://ghfast.top/https://github.com/LovesAsuna/BlogCDN/blob/main/DesignPattern/UML/image-20191229173554296.1262x4wb4mfk.png)
