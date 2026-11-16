plugins {
    id("com.android.application")
    // The Flutter Gradle Plugin must be applied after the Android and Kotlin Gradle plugins.
    id("dev.flutter.flutter-gradle-plugin")
}

android {
    // Must stay com.wegodeliva.customerapp: the Play Store listing already uses this ID (CLAUDE.md §5 Phase 7).
    namespace = "com.wegodeliva.customerapp"
    // Google Play requires API 36 as of late 2026 (CLAUDE.md §4).
    compileSdk = 36
    ndkVersion = flutter.ndkVersion

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }

    defaultConfig {
        applicationId = "com.wegodeliva.customerapp"
        minSdk = flutter.minSdkVersion
        targetSdk = 36
        versionCode = flutter.versionCode
        versionName = flutter.versionName
    }

    flavorDimensions += "environment"
    productFlavors {
        create("dev") {
            dimension = "environment"
            applicationIdSuffix = ".dev"
            resValue("string", "app_name", "WeGoDeliva Dev")
        }
        create("staging") {
            dimension = "environment"
            applicationIdSuffix = ".staging"
            resValue("string", "app_name", "WeGoDeliva Staging")
        }
        create("prod") {
            dimension = "environment"
            resValue("string", "app_name", "WeGoDeliva")
        }
    }

    buildTypes {
        release {
            // Release signing uses the existing upload key from the CI secret store (Phase 7).
            // Never commit keystores or key.properties.
            signingConfig = signingConfigs.getByName("debug")
        }
    }
}

kotlin {
    compilerOptions {
        jvmTarget = org.jetbrains.kotlin.gradle.dsl.JvmTarget.JVM_17
    }
}

flutter {
    source = "../.."
}
