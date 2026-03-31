#include "TagItems.h"
#include <sstream>

TagItems::TagItems(SWIMClient& client, LocaleResource& locale)
    : m_client(client), m_locale(locale) {}

TagItems::~TagItems() {}

std::string TagItems::FormatTime(const std::string& isoTime) const {
    if (isoTime.empty()) return "";
    // Extract HH:MM from ISO 8601 (e.g., "2026-03-30T14:30:00Z" -> "1430")
    size_t tPos = isoTime.find('T');
    if (tPos == std::string::npos || tPos + 6 > isoTime.length()) return isoTime;
    return isoTime.substr(tPos + 1, 2) + isoTime.substr(tPos + 4, 2);
}

std::string TagItems::GetEDCT(const std::string& callsign) const {
    const SWIMFlight* f = m_client.GetFlight(callsign);
    if (!f || f->edct_utc.empty()) return "";
    return FormatTime(f->edct_utc);
}

std::string TagItems::GetCTOT(const std::string& callsign) const {
    const SWIMFlight* f = m_client.GetFlight(callsign);
    if (!f || f->ctot_utc.empty()) return "";
    return FormatTime(f->ctot_utc);
}

std::string TagItems::GetTMIStatus(const std::string& callsign) const {
    const SWIMFlight* f = m_client.GetFlight(callsign);
    if (!f || f->tmi_status.empty()) return "";
    return f->tmi_status;
}

std::string TagItems::GetAMANSequence(const std::string& callsign) const {
    const SWIMFlight* f = m_client.GetFlight(callsign);
    if (!f || f->aman_sequence <= 0) return "";
    return std::to_string(f->aman_sequence);
}

std::string TagItems::GetAMANDelay(const std::string& callsign) const {
    const SWIMFlight* f = m_client.GetFlight(callsign);
    if (!f || f->aman_delay_sec <= 0) return "";
    int minutes = f->aman_delay_sec / 60;
    return std::to_string(minutes) + "m";
}

std::string TagItems::GetTMIDelay(const std::string& callsign) const {
    // TMI delay would come from program data mapped to the flight
    const SWIMFlight* f = m_client.GetFlight(callsign);
    if (!f) return "";
    // Placeholder -- delay calculation requires slot data
    return "";
}

std::string TagItems::GetCDMStatus(const std::string& callsign) const {
    const SWIMFlight* f = m_client.GetFlight(callsign);
    if (!f || f->cdm_status.empty()) return "";
    return f->cdm_status;
}

std::string TagItems::GetFlowStatus(const std::string& callsign) const {
    const SWIMFlight* f = m_client.GetFlight(callsign);
    if (!f || f->flow_measure.empty()) return "";
    return f->flow_measure;
}
