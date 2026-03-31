#include "LocaleResource.h"
#include <fstream>
#include <sstream>
#include <algorithm>

LocaleResource::LocaleResource() : m_locale("en-US") {}
LocaleResource::~LocaleResource() {}

bool LocaleResource::Load(const std::string& localeName, const std::string& pluginDir) {
    std::string path = pluginDir + "\\locales\\" + localeName + ".ini";
    std::ifstream file(path);
    if (!file.is_open()) {
        // Fall back to en-US
        if (localeName != "en-US") {
            return Load("en-US", pluginDir);
        }
        return false;
    }

    std::stringstream buffer;
    buffer << file.rdbuf();
    ParseINI(buffer.str());
    m_locale = localeName;
    return true;
}

void LocaleResource::ParseINI(const std::string& content) {
    std::istringstream stream(content);
    std::string line;
    std::string section;

    while (std::getline(stream, line)) {
        // Trim
        size_t start = line.find_first_not_of(" \t\r\n");
        if (start == std::string::npos) continue;
        line = line.substr(start);

        // Comment
        if (line[0] == ';' || line[0] == '#') continue;

        // Section
        if (line[0] == '[') {
            size_t end = line.find(']');
            if (end != std::string::npos) {
                section = line.substr(1, end - 1);
            }
            continue;
        }

        // Key=Value
        size_t eq = line.find('=');
        if (eq != std::string::npos) {
            std::string key = line.substr(0, eq);
            std::string value = line.substr(eq + 1);
            // Trim key
            key.erase(key.find_last_not_of(" \t") + 1);
            // Trim value
            size_t vs = value.find_first_not_of(" \t");
            if (vs != std::string::npos) value = value.substr(vs);

            std::string fullKey = section.empty() ? key : section + "." + key;
            m_strings[fullKey] = value;
        }
    }
}

std::string LocaleResource::T(const std::string& key) const {
    auto it = m_strings.find(key);
    return (it != m_strings.end()) ? it->second : key;
}

std::string LocaleResource::T(const std::string& key,
                               const std::string& p1, const std::string& v1) const {
    std::map<std::string, std::string> params;
    params[p1] = v1;
    return Interpolate(T(key), params);
}

std::string LocaleResource::T(const std::string& key,
                               const std::string& p1, const std::string& v1,
                               const std::string& p2, const std::string& v2) const {
    std::map<std::string, std::string> params;
    params[p1] = v1;
    params[p2] = v2;
    return Interpolate(T(key), params);
}

std::string LocaleResource::Interpolate(const std::string& tmpl,
                                         const std::map<std::string, std::string>& params) const {
    std::string result = tmpl;
    for (const auto& p : params) {
        std::string placeholder = "{" + p.first + "}";
        size_t pos = 0;
        while ((pos = result.find(placeholder, pos)) != std::string::npos) {
            result.replace(pos, placeholder.length(), p.second);
            pos += p.second.length();
        }
    }
    return result;
}
