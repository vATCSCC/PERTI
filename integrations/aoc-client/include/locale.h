#ifndef LOCALE_H
#define LOCALE_H

#include <string>
#include <map>

class Locale {
public:
    Locale();
    ~Locale();

    bool Load(const std::string& localeName, const std::string& baseDir);
    std::string T(const std::string& key) const;
    std::string T(const std::string& key, const std::string& p1, const std::string& v1) const;
    std::string GetLocale() const { return m_locale; }

private:
    std::string m_locale;
    std::map<std::string, std::string> m_strings;
    void ParseINI(const std::string& content);
};

#endif // LOCALE_H
