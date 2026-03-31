#ifndef LOCALE_RESOURCE_H
#define LOCALE_RESOURCE_H

#include <string>
#include <map>

class LocaleResource {
public:
    LocaleResource();
    ~LocaleResource();

    bool Load(const std::string& localeName, const std::string& pluginDir);
    std::string T(const std::string& key) const;
    std::string T(const std::string& key, const std::string& param1, const std::string& value1) const;
    std::string T(const std::string& key,
                  const std::string& p1, const std::string& v1,
                  const std::string& p2, const std::string& v2) const;
    std::string GetLocale() const { return m_locale; }

private:
    std::string m_locale;
    std::map<std::string, std::string> m_strings;
    void ParseINI(const std::string& content);
    std::string Interpolate(const std::string& tmpl,
                            const std::map<std::string, std::string>& params) const;
};

#endif // LOCALE_RESOURCE_H
