cmake_minimum_required(VERSION 3.16)
project(vatswim-aoc-client VERSION 1.0.0 LANGUAGES CXX)

set(CMAKE_CXX_STANDARD 17)
set(CMAKE_CXX_STANDARD_REQUIRED ON)

# Platform detection
if(WIN32)
    add_definitions(-D_WIN32_WINNT=0x0601)
    set(PLATFORM_LIBS ws2_32 winhttp)
elseif(UNIX)
    find_package(CURL REQUIRED)
    set(PLATFORM_LIBS ${CURL_LIBRARIES} pthread)
endif()

# Sources
set(SOURCES
    src/main.cpp
    src/aoc_client.cpp
    src/swim_api.cpp
    src/sim_msfs.cpp
    src/sim_xplane.cpp
    src/telemetry.cpp
    src/locale.cpp
)

add_executable(vatswim-aoc ${SOURCES})
target_include_directories(vatswim-aoc PRIVATE include)
target_link_libraries(vatswim-aoc PRIVATE ${PLATFORM_LIBS})

# Install
install(TARGETS vatswim-aoc RUNTIME DESTINATION bin)
install(FILES config.example.ini DESTINATION etc)
install(DIRECTORY locales DESTINATION share/vatswim-aoc)
