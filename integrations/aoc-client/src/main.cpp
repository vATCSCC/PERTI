#include "aoc_client.h"
#include <iostream>
#include <csignal>

static AOCClient* g_client = nullptr;

void signalHandler(int sig) {
    (void)sig;
    if (g_client) g_client->Stop();
}

int main(int argc, char* argv[]) {
    std::string configPath = "config.ini";
    if (argc > 1) configPath = argv[1];

    AOCClient client;
    g_client = &client;

    signal(SIGINT, signalHandler);
    signal(SIGTERM, signalHandler);

    if (!client.LoadConfig(configPath)) {
        std::cerr << "Failed to load config: " << configPath << std::endl;
        return 1;
    }

    if (!client.Initialize()) {
        return 1;
    }

    client.Run();
    return 0;
}
