#ifndef TAG_ITEMS_H
#define TAG_ITEMS_H

#include "SWIMClient.h"
#include "LocaleResource.h"
#include <string>

class TagItems {
public:
    TagItems(SWIMClient& client, LocaleResource& locale);
    ~TagItems();

    std::string GetEDCT(const std::string& callsign) const;
    std::string GetCTOT(const std::string& callsign) const;
    std::string GetTMIStatus(const std::string& callsign) const;
    std::string GetAMANSequence(const std::string& callsign) const;
    std::string GetAMANDelay(const std::string& callsign) const;
    std::string GetTMIDelay(const std::string& callsign) const;
    std::string GetCDMStatus(const std::string& callsign) const;
    std::string GetFlowStatus(const std::string& callsign) const;

private:
    SWIMClient& m_client;
    LocaleResource& m_locale;
    std::string FormatTime(const std::string& isoTime) const;
};

#endif // TAG_ITEMS_H
