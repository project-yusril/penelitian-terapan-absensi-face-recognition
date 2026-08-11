allprojects {
    repositories {
        google()
        mavenCentral()
    }
}

val newBuildDir: Directory =
    rootProject.layout.buildDirectory
        .dir("../../build")
        .get()
rootProject.layout.buildDirectory.value(newBuildDir)

subprojects {
    val newSubprojectBuildDir: Directory = newBuildDir.dir(project.name)
    project.layout.buildDirectory.value(newSubprojectBuildDir)
}

// Modul plugin (tflite_flutter, dll.) tidak menyetel JVM target sendiri: task Java jatuh ke
// default 11 sementara Kotlin mengikuti JDK toolchain, dan Gradle menolak dengan
// "Inconsistent JVM-target compatibility". Samakan keduanya dengan :app (17).
//
// Sisi Java harus lewat DSL Android, bukan tasks.withType<JavaCompile>, karena AGP menulis
// ulang source/targetCompatibility saat membuat task. Blok ini juga harus berada sebelum
// evaluationDependsOn(":app") di bawah, yang mengevaluasi :app lebih awal sehingga
// afterEvaluate tidak bisa lagi didaftarkan.
subprojects {
    val alignJavaTarget = {
        (extensions.findByName("android") as? com.android.build.gradle.BaseExtension)
            ?.compileOptions {
                sourceCompatibility = JavaVersion.VERSION_17
                targetCompatibility = JavaVersion.VERSION_17
            }
        Unit
    }
    if (state.executed) alignJavaTarget() else afterEvaluate { alignJavaTarget() }

    tasks.withType<org.jetbrains.kotlin.gradle.tasks.KotlinCompile>().configureEach {
        compilerOptions.jvmTarget.set(org.jetbrains.kotlin.gradle.dsl.JvmTarget.JVM_17)
    }
}

subprojects {
    project.evaluationDependsOn(":app")
}

tasks.register<Delete>("clean") {
    delete(rootProject.layout.buildDirectory)
}
