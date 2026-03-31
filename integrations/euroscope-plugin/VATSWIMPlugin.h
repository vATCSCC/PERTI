#ifndef VATSWIM_PLUGIN_H
#define VATSWIM_PLUGIN_H

// EuroScope Plugin SDK headers would normally be included here
// For development without the SDK, we define the interface

#include "SWIMClient.h"
#include "TagItems.h"
#include "LocaleResource.h"
#include "resource.h"
#include <string>

// Forward declarations for EuroScope SDK types
// In production, include EuroScope SDK headers instead
struct EuroScopePlugInExport;

class CVATSWIMPlugin {
public:
    CVATSWIMPlugin();
    virtual ~CVATSWIMPlugin();

    // EuroScope plugin callbacks (called by EuroScope)
    virtual void OnGetTagItem(int tagItemFunction, const char* callsign,
                              char* itemString, int* colorCode, double* fontSize);
    virtual void OnTimer(int counter);
    virtual void OnFunctionCall(int functionId, const char* itemString,
                                const char* callsign);

    // Configuration
    bool LoadConfig(const std::string& pluginDir);
    bool IsConnected() const { return m_swimClient.IsConnected(); }

private:
    SWIMClient m_swimClient;
    TagItems* m_tagItems;
    LocaleResource m_locale;
    std::string m_airport;
    int m_pollCounter;
};

#endif // VATSWIM_PLUGIN_H
