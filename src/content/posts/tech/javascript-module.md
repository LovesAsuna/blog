---
title: JavaScript 模块化
published: 2024-12-15
description: JavaScript 模块化
image: https://pixiv.nl/93536036.jpg
tags: [Js, module]
category: 技术
draft: false
---

# JavaScript模块化规范

> Js的模块化曾一直是我的疑惑，这个疑惑让我觉得Js甚是繁琐，不想学习，但当了解了之后其实也就那么回事

开篇前首先介绍一下`Node.Js`。Node.js 是能够在服务器端运行JavaScript的开放源代码、跨平台运行环境。

接下来介绍的内容将离不开`Node.js`。

* * *

`Node.Js`能很好的运行 JavaScript，除了有一点，**模块化**。这是因为`Node.js`采用了与ES6完全不同的模块化规范: `CommonJS`，以下将介绍两者的写法与区别。

## ES6规范中的模块化

直接上代码。

```js
//utils.js
//导出变量
export const a = '100'; 

//导出方法
export function sum(a, b) {
    return parseInt(a) + parseInt(b)
}

export function subtract(a, b) {
    return parseInt(a) - parseInt(b)
}

 //导出方法第二种
function catSay(){
   console.log('miao miao'); 
}
export { catSay };

//export default导出
const m = 100;
export default m; 
//export defult const m = 100;// 这里不能写这种格式。

//test.js
import {sum, subtract} from './utils.js'; // 此时的省略情况在后续讨论
console.log(sum(1, 2))
console.log(subtract(10, 3))
```

此处可分为两种用法。

### 浏览器使用

浏览器若要使用ES6的模块化规范，则需要指定type，例如在浏览器中引入上述test.js

```js
<script type="module" src="test.js"></script>
```

另外在test.js中的import**必须**指定后缀名，否则引入的模块无法使用且浏览器不会报错(此错误通常很难排查)

### Node.Js使用

#### Babel

由于`NodeJs`使用的模块化规范不同于ES6，因此ES6的模块化代码是无法直接运行于`Node.Js`中的，需要有一定的工具进行转换。由于借助了工具，工具能够识别出文件的后缀，因此此时的import可以省略后缀。

这里使用的工具为`Babel。`

> Babel 是一个工具链，主要用于将采用 ECMAScript 2015+ 语法编写的代码转换为向后兼容的 JavaScript 语法，以便能够运行在当前和旧版本的浏览器或其他环境中。

- 首先通过命令安装`Babel`: `npm install --global babel-cli`，通过命令`babel --version`查看是否安装成功
- `npm init -y`初始化项目后编写`Babel`的配置文件`.babelrc`
    
    ```json
    {
      "presets": ["es2015"],
      "plugins": []
    }
    ```
    
- 通过命令`npm install --save-dev babel-preset-es2015`安装转码器
- 转码(假设源码都放在`src`目录)
    
    - 转码目录(`--out-dir`或者`-d`指定输出目录)
    
    命令为`babel src -d dist`
    
    - 转码文件(`-o`)
    
    命令为`babel src/test.js -o dist/test.js`
    
- `node dist/test.js`即可运行`test.js`(此时的`test.js`不能运行于浏览器，需要运行于node的web服务器)

#### 直接使用

实际上现在的`Node.Js`已经可以直接运行ES6的模块化代码了，只需要在`package.json`中的`type`设置为`module`即可将项目设置为使用ES6规范的模块化。另外，即使不指定该`type`，只需要将`js`文件的后缀更改为`mjs`，即可强制让`Node.Js`识别此`js`文件为ES6模块化的文件(文件后缀的优先级最高)。但如果不使用`Babel`，`import`操作中的文件后缀**不可省略**。

## CommonJS规范中的模块化

> `CommonJS`定义的模块分为: 模块标识(`module`)、模块定义(`exports`) 、模块引用(`require`)

先看看用法。

```js
//utils.cjs
const sum = function(a, b) { // 或者写function sum
    return parseInt(a) + parseInt(b)
}

const subtract = function(a, b) { // 或者写function subtract
    return parseInt(a) - parseInt(b)
}

module.exports = {
    sum,
    subtract
}

//test.cjs
var m = require('./utils.cjs'); // 只有当后缀为js而不是cjs时，require的文件后缀才可省略
console.log(m.sum(1, 2))
console.log(m.subtract(10, 3))
```

**注意到了吗？文件的后缀名为`.cjs`而不是`.js`**，这意味着让`Node.Js`将此识别为`CommonJS`规范

当没有`package.json`或者`package.json`中的type设置为`commonjs`时node也会识别为`CommonJS`规范

值得注意的是，`CommonJS`的写法在浏览器中是无法被执行的，会出现`Uncaught ReferenceError: require is not defined`

**有无`require`是区分`CommonJS`和`ES6`的重要标志！**

* * *

接下来解释一下`CommonJS`中这几个标识符的意义，先解释`exports`和`module.exports`

在一个node执行一个文件时，会给这个文件内生成一个`exports`和`module`对象，

而`module`又有一个`exports`属性。他们之间的关系如下图，都指向一块{}内存区域。

> exports = module.exports = {};

![module](https://s2.loli.net/2023/06/06/y9ezCMun8DLRKWj.png)

那下面我们来看看代码的吧。

```js
//utils.cjs
let a = 100;

console.log(module.exports); // 能打印出结果为：{}
console.log(exports); // 能打印出结果为：{}

exports.a = 200; // 这里辛苦劳作帮 module.exports 的内容给改成 {a : 200}

exports = '指向其他内存区'; // 这里把exports的指向指走

//test.cjs
var a = require('/utils');
console.log(a) // 打印为 {a : 200}
```

> 从上面可以看出，其实require导出的内容是module.exports的指向的内存块内容，并不是exports的。 简而言之，区分他们之间的区别就是 exports 只是 module.exports的引用，辅助后者添加内容用的。

用白话讲就是，`exports`只辅助`module.exports`操作内存中的数据，真正被`require`出去的内容还是`module.exports`的。

其实大家用内存块的概念去理解，就会很清楚了。

为了避免糊涂，尽量都用 `module.exports` 导出，然后用`require`导入。
