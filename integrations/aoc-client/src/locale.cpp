#include "locale.h"
#include <fstream>
#include <sstream>

Locale::Locale() : m_locale("en-US") {}
Locale::~Locale() {}

bool Locale::Load(const std::string& localeName, const std::string& baseDir) {
    std::string path = baseDir + "/locales/" + localeName + ".ini";
    std::ifstream file(path);
    if (!file.is_open()) {
        if (localeName != "en-US") return Load("en-US", baseDir);
        return false;
    }
    std::stringstream buf;
    buf << file.rdbuf();
    ParseINI(buf.str());
    m_locale = localeName;
    return true;
}

void Locale::ParseINI(const std::string& content) {
    std::istringstream stream(content);
    std::string line, section;
    while (std::getline(stream, line)) {
        size_t start = line.find_first_not_of(" \t\r\n");
        if (start == std::string::npos) continue;
        line = line.substr(start);
        if (line[0] == ';' || line[0] == '#') continue;
        if (line[0] == '[') {
            size_t end = line.find(']');
            if (end != std::string::npos) section = line.substr(1, end - 1);
            continue;
        }
        size_t eq = line.find('=');
        if (eq != std::string::npos) {
            std::string key = line.substr(0, eq);
            std::string value = line.substr(eq + 1);
            key.erase(key.find_last_not_of(" \t") + 1);
            size_t vs = value.find_first_not_of(" \t");
            if (vs != std::string::npos) value = value.substr(vs);
            value.erase(value.find_last_not_of(" \t\r\n") + 1);
            m_strings[section.empty() ? key : section + "." + key] = value;
        }
    }
}

std::string Locale::T(const std::string& key) const {
    auto it = m_strings.find(key);
    return (it != m_strings.end()) ? it->second : key;
}

std::string Locale::T(const std::string& key, const std::string& p1, const std::string& v1) const {
    std::string result = T(key);
    std::string placeholder = "{" + p1 + "}";
    size_t pos = result.find(placeholder);
    if (pos != std::string::npos) {
        result.replace(pos, placeholder.length(), v1);
    }
    return result;
}
