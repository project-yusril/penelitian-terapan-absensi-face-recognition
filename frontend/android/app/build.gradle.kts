import java.util.Properties

plugins {
    id("com.android.application")
    id("kotlin-android")
    // The Flutter Gradle Plugin must be applied after the Android and Kotlin Gradle plugins.
    id("dev.flutter.flutter-gradle-plugin")
}

// L-02: aktifkan Google Services (FCM) hanya bila google-services.json ada.
// Tanpa file ini, plugin akan menggagalkan build; menerapkannya bersyarat
// membuat build lokal/CI tetap jalan sampai Firebase project disediakan.
if (rootProject.file("app/google-services.json").exists()) {
    apply(plugin = "com.google.gms.google-services")
}

android {
    namespace = "com.yusrilekamahendra.absensi_mahasiswa"
    compileSdk = flutter.compileSdkVersion
    ndkVersion = flutter.ndkVersion

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }

    kotlinOptions {
        jvmTarget = JavaVersion.VERSION_17.toString()
    }

    defaultConfig {
        applicationId = "com.yusrilekamahendra.absensi_mahasiswa"
        minSdk = flutter.minSdkVersion
        targetSdk = flutter.targetSdkVersion
        versionCode = flutter.versionCode
        versionName = flutter.versionName
        multiDexEnabled = true
        testInstrumentationRunner = "androidx.test.runner.AndroidJUnitRunner"
    }

    val signingProperties = Properties().apply {
        val file = rootProject.file("key.properties")
        if (file.exists()) file.inputStream().use(::load)
    }
    val releaseStoreFile = System.getenv("ANDROID_KEYSTORE_PATH") ?: signingProperties.getProperty("storeFile")
    val releaseStorePassword = System.getenv("ANDROID_KEYSTORE_PASSWORD") ?: signingProperties.getProperty("storePassword")
    val releaseKeyAlias = System.getenv("ANDROID_KEY_ALIAS") ?: signingProperties.getProperty("keyAlias")
    val releaseKeyPassword = System.getenv("ANDROID_KEY_PASSWORD") ?: signingProperties.getProperty("keyPassword")
    val isReleaseBuild = gradle.startParameter.taskNames.any {
        it.contains("release", ignoreCase = true) || it.contains("bundle", ignoreCase = true)
    }

    signingConfigs {
        create("release") {
            if (releaseStoreFile != null) storeFile = file(releaseStoreFile)
            storePassword = releaseStorePassword
            keyAlias = releaseKeyAlias
            keyPassword = releaseKeyPassword
        }
    }

    buildTypes {
        release {
            require(!isReleaseBuild || listOf(releaseStoreFile, releaseStorePassword, releaseKeyAlias, releaseKeyPassword).all { !it.isNullOrBlank() }) {
                "Release signing belum dikonfigurasi. Gunakan key.properties atau ANDROID_KEYSTORE_* secrets."
            }
            signingConfig = signingConfigs.getByName("release")
        }
    }
}

dependencies {
    androidTestImplementation("androidx.test:runner:1.6.2")
    androidTestImplementation("androidx.test:rules:1.6.1")
}

flutter {
    source = "../.."
}
