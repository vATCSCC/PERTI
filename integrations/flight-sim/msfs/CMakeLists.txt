# VATSWIM MSFS Plugin CMake Build Configuration
#
# Build Instructions:
#
# For WASM (MSFS Community package):
#   1. Install MSFS SDK
#   2. Set MSFS_SDK environment variable
#   3. mkdir build && cd build
#   4. cmake .. -G "Visual Studio 17 2022" -A x64 -DMSFS_WASM=ON
#   5. cmake --build . --config Release
#
# For standalone DLL (testing):
#   1. Install SimConnect SDK
#   2. Set SIMCONNECT_SDK environment variable
#   3. mkdir build && cd build
#   4. cmake .. -G "Visual Studio 17 2022" -A x64
#   5. cmake --build . --config Release

cmake_minimum_required(VERSION 3.20)
project(vatswim_msfs VERSION 1.0.0 LANGUAGES CXX)

set(CMAKE_CXX_STANDARD 17)
set(CMAKE_CXX_STANDARD_REQUIRED ON)

# Options
option(MSFS_WASM "Build as MSFS WASM module" OFF)

# Find SimConnect SDK
if(DEFINED ENV{SIMCONNECT_SDK})
    set(SIMCONNECT_SDK $ENV{SIMCONNECT_SDK})
elseif(DEFINED ENV{MSFS_SDK})
    set(SIMCONNECT_SDK "$ENV{MSFS_SDK}/SimConnect SDK")
else()
    # Default locations
    set(SIMCONNECT_SDK "C:/MSFS SDK/SimConnect SDK")
endif()

if(NOT EXISTS "${SIMCONNECT_SDK}/include/SimConnect.h")
    message(WARNING "SimConnect SDK not found at ${SIMCONNECT_SDK}")
    message(WARNING "Set SIMCONNECT_SDK or MSFS_SDK environment variable")
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
    ${SIMCONNECT_SDK}/include
)

if(MSFS_WASM)
    # MSFS WASM build
    if(DEFINED ENV{MSFS_SDK})
        set(MSFS_SDK $ENV{MSFS_SDK})
    else()
        set(MSFS_SDK "C:/MSFS SDK")
    endif()

    list(APPEND INCLUDE_DIRS
        ${MSFS_SDK}/WASM/include
    )

    add_library(${PROJECT_NAME} SHARED ${SOURCES})

    target_include_directories(${PROJECT_NAME} PRIVATE ${INCLUDE_DIRS})

    target_compile_definitions(${PROJECT_NAME} PRIVATE
        MSFS_WASM
        _MSFS_WASM
        WIN32
        _WINDOWS
    )

    # WASM-specific compiler flags
    target_compile_options(${PROJECT_NAME} PRIVATE
        /W4
        /WX-
        /O2
    )

    # Link against MSFS WASM libraries
    target_link_directories(${PROJECT_NAME} PRIVATE
        ${MSFS_SDK}/WASM/lib
    )

    target_link_libraries(${PROJECT_NAME} PRIVATE
        MSFS
    )

    # Output to package folder
    set_target_properties(${PROJECT_NAME} PROPERTIES
        OUTPUT_NAME "vatswim_msfs"
        SUFFIX ".wasm"
        RUNTIME_OUTPUT_DIRECTORY "${CMAKE_CURRENT_SOURCE_DIR}/package/vatswim-msfs/modules"
    )

else()
    # Standalone DLL build (for testing with SimConnect)
    add_library(${PROJECT_NAME} SHARED ${SOURCES})

    target_include_directories(${PROJECT_NAME} PRIVATE ${INCLUDE_DIRS})

    target_compile_definitions(${PROJECT_NAME} PRIVATE
        WIN32
        _WINDOWS
        _USRDLL
    )

    target_compile_options(${PROJECT_NAME} PRIVATE
        /W4
        /WX-
        /O2
    )

    # Link against SimConnect
    target_link_directories(${PROJECT_NAME} PRIVATE
        ${SIMCONNECT_SDK}/lib
    )

    target_link_libraries(${PROJECT_NAME} PRIVATE
        SimConnect
        winhttp
        ws2_32
    )

    set_target_properties(${PROJECT_NAME} PROPERTIES
        OUTPUT_NAME "vatswim_msfs"
        RUNTIME_OUTPUT_DIRECTORY "${CMAKE_CURRENT_BINARY_DIR}/bin"
    )
endif()

# Install targets
install(TARGETS ${PROJECT_NAME}
    RUNTIME DESTINATION bin
    LIBRARY DESTINATION lib
)

# Copy configuration template
install(FILES
    vatswim_config.ini.example
    DESTINATION bin
    RENAME vatswim_config.ini
)
