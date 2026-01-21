# VATSWIM P3D Plugin CMake Build Configuration
#
# Build Instructions:
#   1. Install P3D SDK (comes with Prepar3D installation)
#   2. Set P3D_SDK environment variable
#   3. mkdir build && cd build
#   4. cmake .. -G "Visual Studio 17 2022" -A x64
#   5. cmake --build . --config Release

cmake_minimum_required(VERSION 3.20)
project(vatswim_p3d VERSION 1.0.0 LANGUAGES CXX)

set(CMAKE_CXX_STANDARD 17)
set(CMAKE_CXX_STANDARD_REQUIRED ON)

# Find P3D SimConnect SDK
if(DEFINED ENV{P3D_SDK})
    set(P3D_SDK $ENV{P3D_SDK})
elseif(DEFINED ENV{SIMCONNECT_SDK})
    set(P3D_SDK $ENV{SIMCONNECT_SDK})
else()
    # Default P3D v5 location
    set(P3D_SDK "C:/Program Files/Lockheed Martin/Prepar3D v5 SDK/SimConnect SDK")
endif()

if(NOT EXISTS "${P3D_SDK}/inc/SimConnect.h")
    message(WARNING "P3D SimConnect SDK not found at ${P3D_SDK}")
    message(WARNING "Set P3D_SDK or SIMCONNECT_SDK environment variable")
endif()

# Source files
set(SOURCES
    src/main.cpp
)

# Include directories
set(INCLUDE_DIRS
    ${CMAKE_CURRENT_SOURCE_DIR}/src
    ${CMAKE_CURRENT_SOURCE_DIR}/../common/simconnect
    ${CMAKE_CURRENT_SOURCE_DIR}/../../../sdk/cpp/include
    ${P3D_SDK}/inc
)

# Create plugin DLL
add_library(${PROJECT_NAME} SHARED ${SOURCES})

target_include_directories(${PROJECT_NAME} PRIVATE ${INCLUDE_DIRS})

target_compile_definitions(${PROJECT_NAME} PRIVATE
    WIN32
    _WINDOWS
    _USRDLL
    _CRT_SECURE_NO_WARNINGS
)

target_compile_options(${PROJECT_NAME} PRIVATE
    /W4
    /WX-
    /O2
)

# Link against SimConnect
target_link_directories(${PROJECT_NAME} PRIVATE
    ${P3D_SDK}/lib
)

target_link_libraries(${PROJECT_NAME} PRIVATE
    SimConnect
    winhttp
    ws2_32
    Shlwapi
)

set_target_properties(${PROJECT_NAME} PROPERTIES
    OUTPUT_NAME "vatswim_p3d"
    RUNTIME_OUTPUT_DIRECTORY "${CMAKE_CURRENT_BINARY_DIR}/bin"
)

# Create add-on.xml for P3D
configure_file(
    "${CMAKE_CURRENT_SOURCE_DIR}/addon/add-on.xml.in"
    "${CMAKE_CURRENT_BINARY_DIR}/bin/add-on.xml"
    @ONLY
)

# Install targets
install(TARGETS ${PROJECT_NAME}
    RUNTIME DESTINATION bin
    LIBRARY DESTINATION lib
)

install(FILES
    "${CMAKE_CURRENT_BINARY_DIR}/bin/add-on.xml"
    "vatswim_p3d.ini.example"
    DESTINATION bin
)
