package org.vatsim.swim;

/**
 * Exception thrown for SWIM API errors
 */
public class SwimApiException extends Exception {
    
    private final int statusCode;
    private final String errorCode;
    
    public SwimApiException(int statusCode, String message) {
        this(statusCode, message, null);
    }
    
    public SwimApiException(int statusCode, String message, String errorCode) {
        super(String.format("[%d] %s: %s", statusCode, errorCode != null ? errorCode : "ERROR", message));
        this.statusCode = statusCode;
        this.errorCode = errorCode;
    }
    
    public int getStatusCode() {
        return statusCode;
    }
    
    public String getErrorCode() {
        return errorCode;
    }
}
