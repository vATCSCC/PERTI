/**
 * VATSWIM C++ SDK - OOOI Detector Example
 *
 * Demonstrates the OOOI (Out, Off, On, In) detection state machine.
 */

#include <swim/swim.h>
#include <stdio.h>
#include <string.h>

/* Simulated flight telemetry data */
typedef struct {
    float groundspeed_kts;
    bool on_ground;
    float altitude_agl_ft;
    float vertical_rate_fpm;
    bool parking_brake;
    const char* description;
} SimulatedFrame;

int main() {
    printf("VATSWIM C++ SDK - OOOI Detector Example\n");
    printf("=======================================\n\n");

    /* Initialize OOOI detector */
    SwimOOOIDetector detector;
    swim_oooi_init(&detector);

    /* Simulated flight frames (representing a complete flight) */
    SimulatedFrame frames[] = {
        /* Parked at gate */
        { 0.0f,  true,  0.0f,    0.0f,  true,  "Parked at gate" },
        { 0.0f,  true,  0.0f,    0.0f,  true,  "Engines starting" },

        /* Pushback and taxi out */
        { 0.0f,  true,  0.0f,    0.0f,  false, "Parking brake released" },
        { 5.0f,  true,  0.0f,    0.0f,  false, "Pushback in progress" },
        { 15.0f, true,  0.0f,    0.0f,  false, "Taxi to runway" },
        { 20.0f, true,  0.0f,    0.0f,  false, "Taxiing" },
        { 0.0f,  true,  0.0f,    0.0f,  false, "Hold short of runway" },

        /* Takeoff */
        { 40.0f,  true,  0.0f,    0.0f,   false, "Entering runway" },
        { 80.0f,  true,  0.0f,    0.0f,   false, "Takeoff roll" },
        { 120.0f, true,  0.0f,    0.0f,   false, "Rotation speed" },
        { 150.0f, false, 100.0f,  2000.0f, false, "Airborne! Climbing" },
        { 180.0f, false, 500.0f,  2500.0f, false, "Initial climb" },
        { 250.0f, false, 2000.0f, 2000.0f, false, "Departure" },

        /* Cruise */
        { 450.0f, false, 35000.0f, 0.0f,    false, "Cruise altitude" },
        { 450.0f, false, 35000.0f, 0.0f,    false, "Cruising" },

        /* Descent and approach */
        { 400.0f, false, 25000.0f, -1500.0f, false, "Top of descent" },
        { 350.0f, false, 15000.0f, -1500.0f, false, "Descending" },
        { 280.0f, false, 5000.0f,  -1000.0f, false, "Approach" },
        { 200.0f, false, 2000.0f,  -800.0f,  false, "Final approach" },
        { 150.0f, false, 500.0f,   -700.0f,  false, "Short final" },

        /* Landing */
        { 140.0f, true,  0.0f,    -500.0f, false, "Touchdown!" },
        { 80.0f,  true,  0.0f,    0.0f,    false, "Rollout" },
        { 30.0f,  true,  0.0f,    0.0f,    false, "Exit runway" },

        /* Taxi in */
        { 20.0f, true,  0.0f,    0.0f,    false, "Taxi to gate" },
        { 15.0f, true,  0.0f,    0.0f,    false, "Taxiing" },
        { 5.0f,  true,  0.0f,    0.0f,    false, "Approaching gate" },

        /* Parked */
        { 0.0f,  true,  0.0f,    0.0f,    true,  "Arrived at gate!" }
    };

    int num_frames = sizeof(frames) / sizeof(frames[0]);

    printf("Simulating flight with %d frames...\n\n", num_frames);
    printf("%-30s | %-10s | %-8s | OOOI Events\n", "Phase", "Zone", "GS (kts)");
    printf("%-30s-+-%-10s-+-%-8s-+------------\n", "------------------------------", "----------", "--------");

    for (int i = 0; i < num_frames; i++) {
        SimulatedFrame* frame = &frames[i];

        /* Update detector */
        bool event = swim_oooi_update(
            &detector,
            frame->groundspeed_kts,
            frame->on_ground,
            frame->altitude_agl_ft,
            frame->vertical_rate_fpm,
            frame->parking_brake
        );

        /* Print frame info */
        printf("%-30s | %-10s | %6.0f  |",
               frame->description,
               swim_zone_to_string(detector.current_zone),
               frame->groundspeed_kts);

        /* Print OOOI event if detected */
        if (event) {
            if (detector.out_detected && detector.times.out_utc > 0) {
                struct tm* tm = gmtime(&detector.times.out_utc);
                if (detector.times.out_utc == time(NULL) || (i > 0 && !frames[i-1].parking_brake && frame->groundspeed_kts > 5)) {
                    if (detector.off_detected && !detector.on_detected) {
                        /* Already past OUT */
                    } else if (!detector.off_detected) {
                        printf(" OUT detected");
                    }
                }
            }
            if (detector.off_detected && !detector.on_detected && detector.times.off_utc > 0) {
                if (frame->on_ground == false && frames[i > 0 ? i-1 : 0].on_ground == true) {
                    printf(" OFF detected");
                }
            }
            if (detector.on_detected && detector.times.on_utc > 0) {
                if (frame->on_ground == true && frames[i > 0 ? i-1 : 0].on_ground == false) {
                    printf(" ON detected");
                }
            }
            if (detector.in_detected && detector.times.in_utc > 0) {
                if (frame->parking_brake && !frames[i > 0 ? i-1 : 0].parking_brake) {
                    printf(" IN detected");
                }
            }
        }

        printf("\n");
    }

    /* Print final OOOI times */
    printf("\n");
    printf("Final OOOI Status:\n");
    printf("==================\n");

    SwimOOOI times;
    swim_oooi_get_times(&detector, &times);

    printf("  OUT: %s", detector.out_detected ? "Detected" : "Not detected");
    if (times.out_utc > 0) {
        char buf[32];
        strftime(buf, sizeof(buf), "%H:%M:%S UTC", gmtime(&times.out_utc));
        printf(" (%s)", buf);
    }
    printf("\n");

    printf("  OFF: %s", detector.off_detected ? "Detected" : "Not detected");
    if (times.off_utc > 0) {
        char buf[32];
        strftime(buf, sizeof(buf), "%H:%M:%S UTC", gmtime(&times.off_utc));
        printf(" (%s)", buf);
    }
    printf("\n");

    printf("  ON:  %s", detector.on_detected ? "Detected" : "Not detected");
    if (times.on_utc > 0) {
        char buf[32];
        strftime(buf, sizeof(buf), "%H:%M:%S UTC", gmtime(&times.on_utc));
        printf(" (%s)", buf);
    }
    printf("\n");

    printf("  IN:  %s", detector.in_detected ? "Detected" : "Not detected");
    if (times.in_utc > 0) {
        char buf[32];
        strftime(buf, sizeof(buf), "%H:%M:%S UTC", gmtime(&times.in_utc));
        printf(" (%s)", buf);
    }
    printf("\n");

    printf("\nFlight complete: %s\n", swim_oooi_is_complete(&detector) ? "YES" : "NO");

    return 0;
}
