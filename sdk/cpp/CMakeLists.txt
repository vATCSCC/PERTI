cmake_minimum_required(VERSION 3.14)
project(swim_sdk_cpp VERSION 1.0.0 LANGUAGES C CXX)

# Options
option(SWIM_BUILD_EXAMPLES "Build example programs" ON)
option(SWIM_USE_CURL "Enable HTTP support via libcurl" ON)

# Header-only library
add_library(swim_sdk INTERFACE)
target_include_directories(swim_sdk INTERFACE
    $<BUILD_INTERFACE:${CMAKE_CURRENT_SOURCE_DIR}/include>
    $<INSTALL_INTERFACE:include>
)

# Curl support
if(SWIM_USE_CURL)
    find_package(CURL REQUIRED)
    target_compile_definitions(swim_sdk INTERFACE SWIM_USE_CURL)
    target_link_libraries(swim_sdk INTERFACE CURL::libcurl)
endif()

# C99 standard for C files, C++11 for C++ files
target_compile_features(swim_sdk INTERFACE c_std_99 cxx_std_11)

# Examples
if(SWIM_BUILD_EXAMPLES)
    add_subdirectory(examples)
endif()

# Installation
include(GNUInstallDirs)
install(TARGETS swim_sdk
    EXPORT swim_sdkTargets
    INCLUDES DESTINATION ${CMAKE_INSTALL_INCLUDEDIR}
)

install(DIRECTORY include/swim
    DESTINATION ${CMAKE_INSTALL_INCLUDEDIR}
)

install(EXPORT swim_sdkTargets
    FILE swim_sdkTargets.cmake
    NAMESPACE swim::
    DESTINATION ${CMAKE_INSTALL_LIBDIR}/cmake/swim_sdk
)

# Package config
include(CMakePackageConfigHelpers)
write_basic_package_version_file(
    "${CMAKE_CURRENT_BINARY_DIR}/swim_sdkConfigVersion.cmake"
    VERSION ${PROJECT_VERSION}
    COMPATIBILITY AnyNewerVersion
)

configure_package_config_file(
    "${CMAKE_CURRENT_SOURCE_DIR}/cmake/swim_sdkConfig.cmake.in"
    "${CMAKE_CURRENT_BINARY_DIR}/swim_sdkConfig.cmake"
    INSTALL_DESTINATION ${CMAKE_INSTALL_LIBDIR}/cmake/swim_sdk
)

install(FILES
    "${CMAKE_CURRENT_BINARY_DIR}/swim_sdkConfig.cmake"
    "${CMAKE_CURRENT_BINARY_DIR}/swim_sdkConfigVersion.cmake"
    DESTINATION ${CMAKE_INSTALL_LIBDIR}/cmake/swim_sdk
)
