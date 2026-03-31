package locale

import (
	"fmt"
	"os"
	"strings"

	"gopkg.in/yaml.v3"
)

// Bundle holds loaded translations for a single locale
type Bundle struct {
	locale string
	keys   map[string]string
}

// Load reads a YAML locale file and flattens nested keys into dot notation
func Load(path, localeName string) (*Bundle, error) {
	data, err := os.ReadFile(path)
	if err != nil {
		return nil, fmt.Errorf("read locale %s: %w", path, err)
	}

	var raw map[string]interface{}
	if err := yaml.Unmarshal(data, &raw); err != nil {
		return nil, fmt.Errorf("parse locale: %w", err)
	}

	b := &Bundle{
		locale: localeName,
		keys:   make(map[string]string),
	}
	flatten(raw, "", b.keys)
	return b, nil
}

// T returns the translation for a key, with optional {placeholder} interpolation.
// Returns the key itself if no translation is found.
func (b *Bundle) T(key string, params ...map[string]string) string {
	val, ok := b.keys[key]
	if !ok {
		return key
	}
	if len(params) > 0 {
		for k, v := range params[0] {
			val = strings.ReplaceAll(val, "{"+k+"}", v)
		}
	}
	return val
}

// Locale returns the bundle's locale name (e.g. "en-US")
func (b *Bundle) Locale() string {
	return b.locale
}

// KeyCount returns the number of loaded translation keys
func (b *Bundle) KeyCount() int {
	return len(b.keys)
}

// flatten recursively converts a nested map into flat dot-notation keys
func flatten(m map[string]interface{}, prefix string, out map[string]string) {
	for k, v := range m {
		key := k
		if prefix != "" {
			key = prefix + "." + k
		}
		switch val := v.(type) {
		case string:
			out[key] = val
		case map[string]interface{}:
			flatten(val, key, out)
		default:
			out[key] = fmt.Sprintf("%v", val)
		}
	}
}
