#include "VATSWIMPlugin.h"
#include <fstream>
#include <sstream>
#include <cstring>

CVATSWIMPlugin::CVATSWIMPlugin()
    : m_tagItems(nullptr)
    , m_pollCounter(0) {
}

CVATSWIMPlugin::~CVATSWIMPlugin() {
    delete m_tagItems;
}

bool CVATSWIMPlugin::LoadConfig(const std::string& pluginDir) {
    std::string configPath = pluginDir + "\\VATSWIMPlugin.ini";
    std::ifstream configFile(configPath);
    if (!configFile.is_open()) {
        return false;
    }

    std::string line;
    std::string baseUrl = "https://perti.vatcscc.org";
    std::string apiKey;
    std::string localeName = "en-US";

    while (std::getline(configFile, line)) {
        // Trim
        size_t start = line.find_first_not_of(" \t");
        if (start == std::string::npos) continue;
        line = line.substr(start);
        if (line[0] == ';' || line[0] == '#' || line[0] == '[') continue;

        size_t eq = line.find('=');
        if (eq == std::string::npos) continue;

        std::string key = line.substr(0, eq);
        std::string value = line.substr(eq + 1);
        key.erase(key.find_last_not_of(" \t") + 1);
        size_t vs = value.find_first_not_of(" \t");
        if (vs != std::string::npos) value = value.substr(vs);
        value.erase(value.find_last_not_of(" \t\r\n") + 1);

        if (key == "base_url") baseUrl = value;
        else if (key == "api_key") apiKey = value;
        else if (key == "airport") m_airport = value;
        else if (key == "locale") localeName = value;
    }

    m_swimClient.SetEndpoint(baseUrl, apiKey);
    m_locale.Load(localeName, pluginDir);
    m_tagItems = new TagItems(m_swimClient, m_locale);

    return !apiKey.empty();
}

void CVATSWIMPlugin::OnGetTagItem(int tagItemFunction, const char* callsign,
                                   char* itemString, int* colorCode, double* fontSize) {
    if (!m_tagItems || !callsign) return;

    std::string cs(callsign);
    std::string result;

    switch (tagItemFunction) {
    case TAG_ITEM_EDCT:
        result = m_tagItems->GetEDCT(cs);
        break;
    case TAG_ITEM_CTOT:
        result = m_tagItems->GetCTOT(cs);
        break;
    case TAG_ITEM_TMI_STATUS:
        result = m_tagItems->GetTMIStatus(cs);
        break;
    case TAG_ITEM_AMAN_SEQ:
        result = m_tagItems->GetAMANSequence(cs);
        break;
    case TAG_ITEM_AMAN_DELAY:
        result = m_tagItems->GetAMANDelay(cs);
        break;
    case TAG_ITEM_TMI_DELAY:
        result = m_tagItems->GetTMIDelay(cs);
        break;
    case TAG_ITEM_CDM_STATUS:
        result = m_tagItems->GetCDMStatus(cs);
        break;
    case TAG_ITEM_FLOW_STATUS:
        result = m_tagItems->GetFlowStatus(cs);
        break;
    }

    if (!result.empty() && itemString) {
        strncpy(itemString, result.c_str(), 15);
        itemString[15] = '\0';
    }
}

void CVATSWIMPlugin::OnTimer(int counter) {
    m_pollCounter++;

    // Poll SWIM every SWIM_POLL_INTERVAL ticks (timer fires every second)
    if (m_pollCounter % (SWIM_POLL_INTERVAL / 1000) == 0) {
        if (!m_airport.empty()) {
            std::vector<SWIMFlight> flights;
            m_swimClient.FetchFlights(m_airport, flights);
        }
        std::vector<SWIMProgram> programs;
        m_swimClient.FetchPrograms(programs);
    }
}

void CVATSWIMPlugin::OnFunctionCall(int functionId, const char* itemString,
                                     const char* callsign) {
    switch (functionId) {
    case TAG_FUNC_SHOW_DETAIL:
        // Open detail popup (to be implemented with EuroScope popup API)
        break;
    case TAG_FUNC_ACK_TMI:
        // Acknowledge TMI (to be implemented)
        break;
    }
}
