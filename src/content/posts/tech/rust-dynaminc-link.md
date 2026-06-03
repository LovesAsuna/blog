---
title: Rust 中的动态链接
published: 2024-12-28
description: Rust 中的动态链接
image: https://ghfast.top/https://raw.githubusercontent.com/xiyan520/tp/master/pc/144.jpg
tags: [Rust]
category: 技术
draft: false
---

# Rust中的动态链接

动态链接的应用非常广泛，像`Linux`的`*.so`文件，Windows上的`*.dll`文件都是动态链接的例子，动态链接的原理可以参考[Linux如何进行动态链接](https://blog.hyosakura.com/archives/32/)。本篇文章主要讲在`Rust`中如何创建动态链接库，为了了解这一点，首先要知道`Rust`中的不同的`crate-type`。文章将从静态链接的用法逐步讲到动态链接的用法。

## crate-type

所有的`crate-type`均参考自[Rust官方文档](https://doc.rust-lang.org/reference/linkage.html)

### bin

> A runnable executable will be produced. This requires that there is a `main` function in the crate which will be run when the program begins executing. This will link in all Rust and native dependencies, producing a single distributable binary. This is the default crate type.

当使用`cargo new`创建项目时，默认创建的就是`bin`类型的项目，这是一种可运行的项目，其目标是生成一个可运行的二进制文件。

`Cargo`有一个惯例：`src/main.rs` 是二进制包的**根文件**，该二进制包的**包名**跟所属`Package`相同，在这里都是`binary`，所有的代码执行都从该文件中的`fn main()`函数开始。

### lib

> A Rust library will be produced. This is an ambiguous concept as to what exactly is produced because a library can manifest itself in several forms. The purpose of this generic `lib` option is to generate the "compiler recommended" style of library. The output library will always be usable by rustc, but the actual type of library may change from time-to-time. The remaining output types are all different flavors of libraries, and the `lib` type can be seen as an alias for one of them (but the actual one is compiler-defined).

当使用`cargo new`创建项目时，如果在后面再加一个`--lib`参数，那此时创建的就是`lib`类型的项目，这是一种作为依赖库的项目，其目标是作为第三方库被其它项目所引用而不是独立运行。

与`src/main.rs`一样，Cargo知道，如果一个`Package`包含有`src/lib.rs`，意味它包含有一个库类型的同名包，该包的根文件是`src/lib.rs`。

值得注意的是，实际上该类型生成的到底是什么，这是一个模糊的概念，因为一个库可以有多种表现形式。这个通用`lib`选项的目的是生成 "编译器推荐 "风格的库。输出的库总是可以被`rustc`使用，但库的实际类型可能会随时改变。其余的输出类型都是不同类型的库，`lib`类型可以看作是其中一种库的别名（但实际类型是编译器定义的，通常会表现为`rlib`）。

### staticlib

> A static system library will be produced. This is different from other library outputs in that the compiler will never attempt to link to `staticlib` outputs. The purpose of this output type is to create a static library containing all of the local crate's code along with all upstream dependencies. This output type will create `*.a` files on Linux, macOS and Windows (MinGW), and `*.lib` files on Windows (MSVC). This format is recommended for use in situations such as linking Rust code into an existing non-Rust application because it will not have dynamic dependencies on other Rust code.

除了`bin`类型和`lib`类型，包含`staticlib`在内的其他类型都需要手动指定，后面不再赘述。想要指定`staticlib`，类型，需要在`Cargo.toml`中添加一节`[lib]`，并在下面写上`crate-type = ["staticlib"]`，从语法上就能看出一个项目支持多种`crate-type`，可以同时指定多种类型。

`staticlib`会生成C风格的静态链接库，具体的用法不是本篇的内容，可以参考其他有关静态编译的文章。

### rlib

> A "Rust library" file will be produced. This is used as an intermediate artifact and can be thought of as a "static Rust library". These `rlib` files, unlike `staticlib` files, are interpreted by the compiler in future linkage. This essentially means that `rustc` will look for metadata in `rlib` files like it looks for metadata in dynamic libraries. This form of output is used to produce statically linked executables as well as `staticlib` outputs.

将`crate-type`指定为`rlib`的库类型将生成一个"Rust库"文件。这种文件常被用作中间工件，可以被认为是一个"静态Rust库"，常见于`Cargo`使用依赖的方式。在用`Cargo`管理依赖时，依赖会被编译成`rlib`被项目所依赖使用。这些`rlib`文件，与`staticlib`文件不同，在未来的链接中会被编译器解释。这实质上意味着`rustc`会像寻找动态库中的元数据一样寻找`rlib`文件中的元数据。这种形式的输出被用来产生静态链接的可执行文件以及静态`lib`输出。

为了演示`rlib`是如何使用的，必须退回到直接使用`rustc`，因为`Cargo`实质上是帮开发者生成了一系列`rustc`的命令并执行。为了了解原理，必须深入到`rustc`(而且`Cargo`并不支持自定义参数透传到`rustc`)。另外在使用`cargo build`的时候加上`-v`可以看到实际调用的`rustc`，也是一种学习的好办法。

以后的章节都将使用相同的项目进行讲解。

#### Library

> src/lib.rs

```rust
pub fn add(a: i32, b: i32) -> i32 {
    a + b
}
```

> Cargo.toml

```toml
[package]
name = "library"
version = "0.1.0"
edition = "2021"

# See more keys and their definitions at https://doc.rust-lang.org/cargo/reference/manifest.html

[dependencies]
```

#### Binary

> src/main.rs

```rust
fn main() {
    println!("{}", library::add(1, 2));
}
```

最常见的使用`rlib`的场景就是在`Cargo.toml`中声明一个`dependency`

> Cargo.toml

```toml
[package]
name = "binary"
version = "0.1.0"
edition = "2021"

# See more keys and their definitions at https://doc.rust-lang.org/cargo/reference/manifest.html
[dependencies]
library = { path = "相对于Binrary的路径" }
# library = "0" 如果上传到了crates.io也可直接写版本
```

此时使用`cargo build -v`，可以看到`Cargo`为我们生成了两条`rustc`命令(以下是简化版本)

1. rustc --crate-name library library\\src\\lib.rs --crate-type lib --out-dir .\\target\\debug\\deps -L dependency=.\\target\\debug\\deps
2. rustc --crate-name binary src\\main.rs --crate-type bin --out-dir .\\target\\debug\\deps -L dependency=.\\target\\debug\\deps --extern library=.\\target\\debug\\deps\\liblibrary.rlib

第一行命令为`Library`生成了一个`rlib`文件，而第二行命令则是引用了这个`rlib`文件，并为`Binary`生成了可运行的二进制文件，这里是一个静态链接的过程。

但我们实际上完全可以分开编译，不管用不用`Cargo`，只需要确保在编译`Binary`时传给`--extern`的参数有一个在源码中的引用(即`prinyln!`中的`library::`)，而`Cargo`帮我们做的最重要的事情就是遍历`dependecies`节并逐一将依赖编译为`rlib`，并在编译`Binary`确保给每个依赖的引用添加上`--extern`

### dylib

> A dynamic Rust library will be produced. This is different from the `lib` output type in that this forces dynamic library generation. The resulting dynamic library can be used as a dependency for other libraries and/or executables. This output type will create `*.so` files on Linux, `*.dylib` files on macOS, and `*.dll` files on Windows.

这节开始本篇文章的主题。`dylib`是一种专门给`Rust`设计使用的动态链接库(但其他语言也可使用)。由于其专门为`Rust`而使用，因此它的使用方法跟`rlib`非常类似。

#### 依赖

这种使用方法跟`rlib`，大体一致，唯一不同的是在`Library`的`Cargo.toml`中将其类型更改为了`dylib`。

> Cargo.toml

```toml
[package]
name = "library"
version = "0.1.0"
edition = "2021"

# See more keys and their definitions at https://doc.rust-lang.org/cargo/reference/manifest.html

[lib]
crate-type = ["dylib"]

[dependencies]
```

在使用依赖的这种方式，`Cargo`会帮我们解决一切事情，但也就和`Library`耦合在了一起。如果在没有库项目源码，或库没有上传到`crates.io`而库只分发了动态链接库文件的情况下，就有必要探讨该如何直接链接动态链接库文件了。

#### 直接链接

在前一种使用依赖的方法中，利用`cargo build -v`来看一下`Cargo`帮我们做了什么(这里同样是简化版本)

1. rustc --crate-name library library\\src\\lib.rs --crate-type dylib -C prefer-dynamic --out-dir .\\target\\debug\\deps -L dependency=.\\target\\debug\\deps
2. rustc --crate-name binary src\\main.rs --crate-type bin --out-dir .\\target\\debug\\deps -L dependency=.\\target\\debug\\deps --extern library=.\\target\\debug\\deps\\library.dll

可以看到它的`rustc`命令与使用`rlib`时非常相似，不同的地方只在于`extern`从`rlib`换成了动态链接库文件，同时在编译库的时候加了一个`-C prefer-dynamic`。这是一个非常重要的参数，这是由于`Rust`在编译时，默认会静态链接上代码中用到的标准库，而如果动态链接库文件中也带上了标准库，就会产生冲突，无法通过编译(后面会有其他办法解决)。

这是由于`Rust`不允许[同一个依赖出现两次](https://doc.rust-lang.org/reference/linkage.html)，因此同一个库(标准库)的不同版本混杂在一起会导致未定义行为的发生，因此`Rust`直接将其杜绝掉了(但要明白这是技术上允许的事情，后面会看到)

> A major goal of the compiler is to ensure that a library never appears more than once in any artifact. For example, if dynamic libraries B and C were each statically linked to library A, then a crate could not link to B and C together because there would be two copies of A. The compiler allows mixing the rlib and dylib formats, but this restriction must be satisfied.

因此作为`dylib`库的分发者和使用者，分别需要做不同的事情：

##### 分发者

确保在编译`dylib`时，带上`-C prefer-dynamic`。然而直接在`lib`项目上进行编译，是无法通过`Cargo`将该参数直接透传到`rustc`的。也许你会想到用`build.rs`即`buildscript`中的`cargo:rustc-link-arg=FLAG`，但仔细看会发现这个参数只能在`benchmarks, binaries, cdylib crates, examples, and tests`中使用，并没有`dylib`，`Cargo`会将传入的`-C prefer-dynamic`忽略，因此你只能手动使用`rustc`来编译`dylib`。

有一种另辟蹊径的方式就是像上面一样使用依赖来解决：创建一个什么都不做的项目依赖此`dylib`库项目，`Cargo`就会帮你做好所有的事情。

##### 使用者

当使用`Cargo`直接链接动态链接库时，需要进行声明，以`Library`作为例子就是需要在使用者，即`Binary`的`main.rs`内写上`extern crate library`。有了前面的经验，其实我们可以知道这一行实际上是写给`Cargo`看的，目的是为了让`Cargo`添加上`--extern library=library.dll`。因为此时使用的是直接链接，在`Cargo.toml`中并没有依赖的声明，`Cargo`并不知道这一个引用是从哪来的，也就会造成编译错误。当然，如果你直接使用`rustc`进行编译并手动添加上`--extern`，那么这一行`extern crate library`也是可以不写的。

另外值得一提的是，演示环境使用`Windows`，`Library`编译生成的产物有`library.dll`和`library.dll.lib`，后者是用来给编译器验证和填写一些元数据的，编译时实际上不需要使用到前者(实际两个都用到)，只有在真正运行二进制文件并动态链接时才会用到前者而不会用到后者，两者分别用在不同阶段。

另外我们还需要告诉`Cargo`如何进行动态链接库文件的查找(直接使用`rustc`可以忽略)，这时候需要用到`build.rs`。

> build.rs

```rust
fn main() {
    println!("cargo:rustc-link-search=./")
}
```

这里的代码意思是告诉`Cargo`在当前目录寻找动态链接库文件，也就是`library.dll`。当有了`extern crate library`也指定了链接的寻找路径，`Cargo`就可以正确的生成`rustc`命令进行编译了。

还有非常重要的一点就是，此时的可运行二进制文件也将变成动态链接到标准库，**无论使用的是`Cargo`依赖方式还是直接链接方式**，都必须动态链接**标准库的动态链接库文件**和**依赖库的动态链接库文件**，标准库的动态链接库文件在`toolchain`目录下可以找到，并且特定于`Rust`版本，标准库的动态链接库文件形如`std-7ec7d4eeb6a255ec.dll`，这也就意味着要使用`dylib`，必须保证**使用同一个Rust版本**，同时缺乏了标准库也就意味着该动态链接库在被其他语言使用前也必须先加载一个`Rust`的标准库的动态链接库。

#### extern语句块

前面简略提到过了`dylib`也可以被其他语言使用的原因就在于这，这种使用方法与后面将介绍的`cdylib`完全一致，并且可以避免需要动态链接标准库的问题。

##### Library

> src/lib.rs

```rust
#[no_mangle]
pub fn add(a: i32, b: i32) -> i32 {
    a + b
}
```

由于这种方式使用了`C ABI`，因此必须给方法加一个`#[no_mangle]`，指示编译器在编译时不要更改方法名。

> Cargo.toml

```toml
[package]
name = "library"
version = "0.1.0"
edition = "2021"

# See more keys and their definitions at https://doc.rust-lang.org/cargo/reference/manifest.html

[lib]
crate-type = ["dylib"]

[dependencies]
```

##### Binary

> src/main.rs

```rust
fn main() {
    unsafe {
        println!("{}", add(1, 2));
    }
}

#[link(name = "library")]
extern "C" {
    fn add(a: i32, b: i32) -> i32;
}
```

> build.rs

```rust
fn main() {
    println!("cargo:rustc-link-search=./")
}
```

为了方便演示这里就使用`Cargo + build.rs`来及进行演示，实际上利用之前的经验，你完全可以直接使用`rustc`加上`-L`参数(通过`cargo build -v`知道)来完成。如果不加`-L`默认会寻找当前路径。

这里要说的问题是上述代码中的`#[link(name = "library")]`会指示链接器寻找`library.lib`，与实际的默认编译产物不符(不确定其他平台是否有这种问题)，有两种办法解决：

1. 手动修改`library.dll.lib`的名称为`library.lib`
2. 修改`#[link(name = "library")]`为`#[link(name = "library.dll")]`，但是这种方法丧失了跨平台的兼容性

使用`extern`语句块的方式，在编译依赖库的时候，无论是否加上`-C prefer-dynamic`都不影响**编译**，但加上了`-C prefer-dynamic`将导致使用者在使用也需要链接上标准库，因此强行绕过限制编译只会导致增加二进制文件的大小但还是需要链接标准库，这与`cdylib`是一样的(下一节会说)。因此在使用`dylib`时还是推荐使用依赖的方式或者是直接链接的方式。

### cdylib

> A dynamic system library will be produced. This is used when compiling a dynamic library to be loaded from another language. This output type will create `*.so` files on Linux, `*.dylib` files on macOS, and `*.dll` files on Windows.

`cdylib`是一种专门给设计为跨语言使用的动态链接库类型，其生成的动态链接库将会使用`C ABI`，这种类型的`lib`与前面提到的`dylib`中的`extern`语句块方式完全一样，这里就不再次贴代码了。

有一点值得注意的是，`dylib`可以使用`extern`语句块的方式，而`cdyliib`却不可以使用`extern crate`，这会导致编译错误。其根本原因在于在`dylib`在编译出的动态链接库中会有特定的可以给`rustc`识别的一节(`.rustc`)，同样利用前面的经验你可以强行使用`--extern`来指定`cdylib`编译出的动态链接库来编译看到此错误。

你或许会疑惑`cdylib`中不就是静态链接了(部分)标准库，依赖库动态链接了标准库，而导致有了两个标准库？答案是肯定的，然而正是因为在动态库链接库文件中有这特殊的`.rustc`，在`dylib`中编译器可以通过此来识别依赖关系发现有两个相同的标准库。而在通过`extern`语句块使用`dylib`时(编译和使用上与`cdylib`完全一致)，是通过`C ABI`来进行交互的，编译器不会去看依赖关系，也就绕过了此限制，但也导致未定义行为的发生。而在`cdylib`中，也的确存在了两份标准库，会导致内存的占用加大，但这种方式可以让动态链接库单独被其他语言通过`C API`使用。

## 总结

`dylib`和`cdylib`各有各的好处，选择哪个全靠自己权衡利弊。

`dylib`有多种使用方式。其中依赖和直接链接必须在编译时添加`-C prefer-dynamic`，并在使用时链接上标准库；`extern`语句块方式的编译方式则和`cdylib`完全一致，不需要考虑`-C prefer-dynamic`，使用上则取决于是否需要链接标准库。(加上`-C prefer-dynamic`会使得可运行二进制文件和动态链接库文件体积都比较小)

`cdylib`编译方式则和`dylib`的`extern`语句块的编译方式完全一致，使用时也不需要考虑链接标准库，因为已经静态链接在了动态链接库文件内。(由于有两份标准库，因此体积都比较大)
