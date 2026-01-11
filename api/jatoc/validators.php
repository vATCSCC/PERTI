<?php
/**
 * JATOC Input Validation Functions
 *
 * Provides validation for all JATOC API input fields.
 * Each function returns null if valid, or an error message string if invalid.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/datetime.php';

class JatocValidators {

    /**
     * Validate facility code (2-8 alphanumeric characters)
     *
     * @param mixed $value Facility code to validate
     * @param bool $required Whether field is required
     * @return string|null Error message or null if valid
     */
    public static function facilityCode($value, $required = true) {
        if (empty($value)) {
            return $required ? 'Facility code is required' : null;
        }

        $value = strtoupper(trim($value));
        if (!preg_match('/^[A-Z0-9]{2,8}$/', $value)) {
            return 'Facility code must be 2-8 alphanumeric characters';
        }

        return null;
    }

    /**
     * Validate incident type (ATC_ZERO, ATC_ALERT, etc.)
     *
     * @param mixed $value Incident type to validate
     * @param bool $required Whether field is required
     * @return string|null Error message or null if valid
     */
    public static function incidentType($value, $required = true) {
        if (empty($value)) {
            return $required ? 'Incident type is required' : null;
        }

        if (!array_key_exists($value, JATOC_INCIDENT_TYPES)) {
            return 'Invalid incident type. Must be one of: ' . implode(', ', array_keys(JATOC_INCIDENT_TYPES));
        }

        return null;
    }

    /**
     * Validate lifecycle status (PENDING, ACTIVE, CLOSED, etc.)
     *
     * @param mixed $value Lifecycle status to validate
     * @param bool $required Whether field is required
     * @return string|null Error message or null if valid
     */
    public static function lifecycleStatus($value, $required = false) {
        if (empty($value)) {
            return $required ? 'Lifecycle status is required' : null;
        }

        if (!array_key_exists($value, JATOC_LIFECYCLE_STATUSES)) {
            return 'Invalid lifecycle status. Must be one of: ' . implode(', ', array_keys(JATOC_LIFECYCLE_STATUSES));
        }

        return null;
    }

    /**
     * Validate trigger code (A-W)
     *
     * @param mixed $value Trigger code to validate
     * @param bool $required Whether field is required
     * @return string|null Error message or null if valid
     */
    public static function triggerCode($value, $required = false) {
        if (empty($value)) {
            return $required ? 'Trigger code is required' : null;
        }

        if (!array_key_exists($value, JATOC_TRIGGERS)) {
            return 'Invalid trigger code. Must be one of: ' . implode(', ', array_keys(JATOC_TRIGGERS));
        }

        return null;
    }

    /**
     * Validate facility type (ARTCC, TRACON, ATCT, etc.)
     *
     * @param mixed $value Facility type to validate
     * @param bool $required Whether field is required
     * @return string|null Error message or null if valid
     */
    public static function facilityType($value, $required = false) {
        if (empty($value)) {
            return $required ? 'Facility type is required' : null;
        }

        if (!array_key_exists($value, JATOC_FACILITY_TYPES)) {
            return 'Invalid facility type. Must be one of: ' . implode(', ', array_keys(JATOC_FACILITY_TYPES));
        }

        return null;
    }

    /**
     * Validate datetime string
     *
     * @param mixed $value Datetime string to validate
     * @param bool $required Whether field is required
     * @return string|null Error message or null if valid
     */
    public static function datetime($value, $required = true) {
        if (empty($value)) {
            return $required ? 'Datetime is required' : null;
        }

        if (!JatocDateTime::isValid($value)) {
            return 'Invalid datetime format. Use ISO 8601 (YYYY-MM-DDTHH:MM:SS) or YYYY-MM-DD HH:MM:SS';
        }

        return null;
    }

    /**
     * Validate string length
     *
     * @param mixed $value String to validate
     * @param int $min Minimum length
     * @param int|null $max Maximum length (null for no limit)
     * @param string $fieldName Field name for error message
     * @return string|null Error message or null if valid
     */
    public static function stringLength($value, $min = 0, $max = null, $fieldName = 'Field') {
        if ($value === null) {
            $value = '';
        }

        $len = strlen($value);

        if ($len < $min) {
            return "$fieldName must be at least $min characters";
        }

        if ($max !== null && $len > $max) {
            return "$fieldName must not exceed $max characters";
        }

        return null;
    }

    /**
     * Validate ops level (1, 2, or 3)
     *
     * @param mixed $value Ops level to validate
     * @param bool $required Whether field is required
     * @return string|null Error message or null if valid
     */
    public static function opsLevel($value, $required = true) {
        if ($value === null || $value === '') {
            return $required ? 'Ops level is required' : null;
        }

        $level = (int)$value;
        if (!array_key_exists($level, JATOC_OPS_LEVELS)) {
            return 'Invalid ops level. Must be 1, 2, or 3';
        }

        return null;
    }

    /**
     * Validate update type
     *
     * @param mixed $value Update type to validate
     * @param bool $required Whether field is required
     * @return string|null Error message or null if valid
     */
    public static function updateType($value, $required = false) {
        if (empty($value)) {
            return $required ? 'Update type is required' : null;
        }

        if (!array_key_exists($value, JATOC_UPDATE_TYPES)) {
            return 'Invalid update type. Must be one of: ' . implode(', ', array_keys(JATOC_UPDATE_TYPES));
        }

        return null;
    }

    /**
     * Validate daily ops item type
     *
     * @param mixed $value Item type to validate
     * @param bool $required Whether field is required
     * @return string|null Error message or null if valid
     */
    public static function dailyOpsType($value, $required = true) {
        if (empty($value)) {
            return $required ? 'Item type is required' : null;
        }

        if (!array_key_exists($value, JATOC_DAILY_OPS_TYPES)) {
            return 'Invalid item type. Must be one of: ' . implode(', ', array_keys(JATOC_DAILY_OPS_TYPES));
        }

        return null;
    }

    /**
     * Validate personnel element
     *
     * @param mixed $value Element to validate
     * @param bool $required Whether field is required
     * @return string|null Error message or null if valid
     */
    public static function personnelElement($value, $required = true) {
        if (empty($value)) {
            return $required ? 'Element is required' : null;
        }

        if (!in_array($value, JATOC_PERSONNEL_ELEMENTS)) {
            return 'Invalid element. Must be one of: ' . implode(', ', JATOC_PERSONNEL_ELEMENTS);
        }

        return null;
    }

    /**
     * Validate incident creation payload
     *
     * @param array $data Input data array
     * @return array|null Array of error messages or null if valid
     */
    public static function incidentCreate($data) {
        $errors = [];

        // Required fields
        if ($err = self::facilityCode($data['facility'] ?? null, true)) $errors[] = $err;
        if ($err = self::datetime($data['start_utc'] ?? null, true)) $errors[] = $err;

        // Check for incident type (support both old 'status' and new 'incident_type' field names)
        $incType = $data['incident_type'] ?? $data['status'] ?? null;
        if ($err = self::incidentType($incType, true)) $errors[] = $err;

        // Optional fields
        if ($err = self::triggerCode($data['trigger_code'] ?? null, false)) $errors[] = $err;
        if ($err = self::facilityType($data['facility_type'] ?? null, false)) $errors[] = $err;

        // Check for lifecycle status (support both old 'incident_status' and new 'lifecycle_status')
        $lifecycle = $data['lifecycle_status'] ?? $data['incident_status'] ?? 'ACTIVE';
        if ($err = self::lifecycleStatus($lifecycle, false)) $errors[] = $err;

        if ($err = self::stringLength($data['remarks'] ?? '', 0, 4000, 'Remarks')) $errors[] = $err;

        return empty($errors) ? null : $errors;
    }

    /**
     * Validate incident update payload
     *
     * @param array $data Input data array
     * @return array|null Array of error messages or null if valid
     */
    public static function incidentUpdate($data) {
        $errors = [];

        // Only validate fields that are present
        if (isset($data['facility'])) {
            if ($err = self::facilityCode($data['facility'], true)) $errors[] = $err;
        }

        // Check for incident type (support both field names)
        $incType = $data['incident_type'] ?? $data['status'] ?? null;
        if ($incType !== null) {
            if ($err = self::incidentType($incType, true)) $errors[] = $err;
        }

        if (isset($data['start_utc'])) {
            if ($err = self::datetime($data['start_utc'], false)) $errors[] = $err;
        }

        if (isset($data['update_utc'])) {
            if ($err = self::datetime($data['update_utc'], false)) $errors[] = $err;
        }

        if (isset($data['closeout_utc'])) {
            if ($err = self::datetime($data['closeout_utc'], false)) $errors[] = $err;
        }

        if (isset($data['trigger_code'])) {
            if ($err = self::triggerCode($data['trigger_code'], false)) $errors[] = $err;
        }

        if (isset($data['facility_type'])) {
            if ($err = self::facilityType($data['facility_type'], false)) $errors[] = $err;
        }

        // Check for lifecycle status (support both field names)
        $lifecycle = $data['lifecycle_status'] ?? $data['incident_status'] ?? null;
        if ($lifecycle !== null) {
            if ($err = self::lifecycleStatus($lifecycle, false)) $errors[] = $err;
        }

        if (isset($data['remarks'])) {
            if ($err = self::stringLength($data['remarks'], 0, 4000, 'Remarks')) $errors[] = $err;
        }

        return empty($errors) ? null : $errors;
    }

    /**
     * Validate update creation payload
     *
     * @param array $data Input data array
     * @return array|null Array of error messages or null if valid
     */
    public static function updateCreate($data) {
        $errors = [];

        if (empty($data['incident_id'])) {
            $errors[] = 'Incident ID is required';
        }

        if (isset($data['update_type'])) {
            if ($err = self::updateType($data['update_type'], false)) $errors[] = $err;
        }

        if ($err = self::stringLength($data['remarks'] ?? '', 0, 4000, 'Remarks')) $errors[] = $err;

        return empty($errors) ? null : $errors;
    }
}
