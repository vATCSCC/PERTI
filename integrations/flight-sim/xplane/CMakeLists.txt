# VATSWIM X-Plane Plugin CMake Build Configuration
#
# Build Instructions:
#   1. Download X-Plane SDK from https://developer.x-plane.com/sdk/
#   2. Set XPLANE_SDK environment variable
#   3. mkdir build && cd build
#   4. cmake .. -G "Visual Studio 17 2022" -A x64  (Windows)
#      cmake .. -G "Xcode"                          (macOS)
#      cmake ..                                      (Linux)
#   5. cmake --build . --config Release

cmake_minimum_required(VERSION 3.20)
project(vatswim_xplane VERSION 1.0.0 LANGUAGES C CXX)

set(CMAKE_C_STANDARD 11)
set(CMAKE_CXX_STANDARD 17)

# Find X-Plane SDK
if(DEFINED ENV{XPLANE_SDK})
    set(XPLANE_SDK $ENV{XPLANE_SDK})
else()
    # Default locations
    if(WIN32)
        set(XPLANE_SDK "C:/X-Plane SDK")
    elseif(APPLE)
        set(XPLANE_SDK "$ENV{HOME}/X-Plane SDK")
    else()
        set(XPLANE_SDK "/opt/X-Plane SDK")
    endif()
endif()

if(NOT EXISTS "${XPLANE_SDK}/CHeaders/XPLM/XPLMPlugin.h")
    message(WARNING "X-Plane SDK not found at ${XPLANE_SDK}")
    message(WARNING "Set XPLANE_SDK environment variable")
endif()

# Source files
set(SOURCES
    src/main.c
)

# Include directories
set(INCLUDE_DIRS
    ${CMAKE_CURRENT_SOURCE_DIR}/src
    ${CMAKE_CURRENT_SOURCE_DIR}/../../../sdk/cpp/include
    ${XPLANE_SDK}/CHeaders/XPLM
    ${XPLANE_SDK}/CHeaders/Widgets
    ${XPLANE_SDK}/CHeaders/Wrappers
)

# Create plugin library
add_library(${PROJECT_NAME} SHARED ${SOURCES})

target_include_directories(${PROJECT_NAME} PRIVATE ${INCLUDE_DIRS})

# Platform-specific settings
if(WIN32)
    target_compile_definitions(${PROJECT_NAME} PRIVATE
        IBM=1
        APL=0
        LIN=0
        WIN32
        _WINDOWS
        _CRT_SECURE_NO_WARNINGS
    )

    target_link_directories(${PROJECT_NAME} PRIVATE
        ${XPLANE_SDK}/Libraries/Win
    )

    target_link_libraries(${PROJECT_NAME} PRIVATE
        XPLM_64
        XPWidgets_64
        winhttp
        ws2_32
    )

    set_target_properties(${PROJECT_NAME} PROPERTIES
        OUTPUT_NAME "vatswim_xplane"
        SUFFIX ".xpl"
    )

elseif(APPLE)
    target_compile_definitions(${PROJECT_NAME} PRIVATE
        IBM=0
        APL=1
        LIN=0
    )

    find_library(XPLM_LIB XPLM PATHS ${XPLANE_SDK}/Libraries/Mac)
    find_library(XPWidgets_LIB XPWidgets PATHS ${XPLANE_SDK}/Libraries/Mac)

    target_link_libraries(${PROJECT_NAME} PRIVATE
        ${XPLM_LIB}
        ${XPWidgets_LIB}
        "-framework CoreFoundation"
        "-framework Security"
        curl
    )

    set_target_properties(${PROJECT_NAME} PROPERTIES
        OUTPUT_NAME "vatswim_xplane"
        SUFFIX ".xpl"
        BUNDLE TRUE
        MACOSX_BUNDLE_INFO_PLIST ${CMAKE_CURRENT_SOURCE_DIR}/Resources/Info.plist.in
    )

else() # Linux
    target_compile_definitions(${PROJECT_NAME} PRIVATE
        IBM=0
        APL=0
        LIN=1
    )

    target_compile_options(${PROJECT_NAME} PRIVATE
        -fPIC
        -fvisibility=hidden
    )

    target_link_libraries(${PROJECT_NAME} PRIVATE
        curl
        pthread
    )

    set_target_properties(${PROJECT_NAME} PROPERTIES
        OUTPUT_NAME "lin.xpl"
        PREFIX ""
        SUFFIX ""
    )
endif()

# Common settings
target_compile_definitions(${PROJECT_NAME} PRIVATE
    XPLM200=1
    XPLM210=1
    XPLM300=1
    XPLM301=1
)

# Output directory structure for X-Plane plugin
set(PLUGIN_DIR "${CMAKE_CURRENT_SOURCE_DIR}/plugin/vatswim")

if(WIN32)
    set(PLATFORM_DIR "${PLUGIN_DIR}/win_x64")
elseif(APPLE)
    set(PLATFORM_DIR "${PLUGIN_DIR}/mac_x64")
else()
    set(PLATFORM_DIR "${PLUGIN_DIR}/lin_x64")
endif()

set_target_properties(${PROJECT_NAME} PROPERTIES
    RUNTIME_OUTPUT_DIRECTORY "${PLATFORM_DIR}"
    LIBRARY_OUTPUT_DIRECTORY "${PLATFORM_DIR}"
)

# Copy configuration template
add_custom_command(TARGET ${PROJECT_NAME} POST_BUILD
    COMMAND ${CMAKE_COMMAND} -E copy_if_different
        "${CMAKE_CURRENT_SOURCE_DIR}/vatswim_config.txt.example"
        "${PLUGIN_DIR}/vatswim_config.txt"
    COMMENT "Copying configuration template"
)

# Install targets
install(DIRECTORY ${PLUGIN_DIR}
    DESTINATION plugins
)
