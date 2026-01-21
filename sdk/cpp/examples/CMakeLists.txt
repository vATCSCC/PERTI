cmake_minimum_required(VERSION 3.14)

# Basic ingest example
add_executable(basic_ingest basic_ingest.cpp)
target_link_libraries(basic_ingest swim_sdk)

# OOOI detector example
add_executable(oooi_detector oooi_detector.cpp)
target_link_libraries(oooi_detector swim_sdk)
