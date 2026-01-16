package org.vatsim.swim.model;

import com.fasterxml.jackson.annotation.JsonIgnoreProperties;
import com.fasterxml.jackson.annotation.JsonProperty;
import java.util.List;
import java.util.ArrayList;

/**
 * Pagination information
 */
@JsonIgnoreProperties(ignoreUnknown = true)
public class Pagination {
    
    @JsonProperty("total")
    private int total;
    
    @JsonProperty("page")
    private int page;
    
    @JsonProperty("per_page")
    private int perPage;
    
    @JsonProperty("total_pages")
    private int totalPages;
    
    @JsonProperty("has_more")
    private boolean hasMore;
    
    // Getters and setters
    public int getTotal() { return total; }
    public void setTotal(int total) { this.total = total; }
    
    public int getPage() { return page; }
    public void setPage(int page) { this.page = page; }
    
    public int getPerPage() { return perPage; }
    public void setPerPage(int perPage) { this.perPage = perPage; }
    
    public int getTotalPages() { return totalPages; }
    public void setTotalPages(int totalPages) { this.totalPages = totalPages; }
    
    public boolean isHasMore() { return hasMore; }
    public void setHasMore(boolean hasMore) { this.hasMore = hasMore; }
}

/**
 * Flights list response
 */
@JsonIgnoreProperties(ignoreUnknown = true)
class FlightsResponse {
    
    @JsonProperty("success")
    private boolean success;
    
    @JsonProperty("data")
    private List<Flight> data = new ArrayList<>();
    
    @JsonProperty("pagination")
    private Pagination pagination;
    
    @JsonProperty("timestamp")
    private String timestamp;
    
    // Getters and setters
    public boolean isSuccess() { return success; }
    public void setSuccess(boolean success) { this.success = success; }
    
    public List<Flight> getData() { return data; }
    public void setData(List<Flight> data) { this.data = data; }
    
    public Pagination getPagination() { return pagination; }
    public void setPagination(Pagination pagination) { this.pagination = pagination; }
    
    public String getTimestamp() { return timestamp; }
    public void setTimestamp(String timestamp) { this.timestamp = timestamp; }
}

/**
 * Single flight response
 */
@JsonIgnoreProperties(ignoreUnknown = true)
class FlightResponse {
    
    @JsonProperty("success")
    private boolean success;
    
    @JsonProperty("data")
    private Flight data;
    
    @JsonProperty("timestamp")
    private String timestamp;
    
    // Getters and setters
    public boolean isSuccess() { return success; }
    public void setSuccess(boolean success) { this.success = success; }
    
    public Flight getData() { return data; }
    public void setData(Flight data) { this.data = data; }
    
    public String getTimestamp() { return timestamp; }
    public void setTimestamp(String timestamp) { this.timestamp = timestamp; }
}

/**
 * TMI programs response wrapper
 */
@JsonIgnoreProperties(ignoreUnknown = true)
class TmiProgramsResponse {
    
    @JsonProperty("success")
    private boolean success;
    
    @JsonProperty("data")
    private TmiPrograms data;
    
    @JsonProperty("timestamp")
    private String timestamp;
    
    // Getters and setters
    public boolean isSuccess() { return success; }
    public void setSuccess(boolean success) { this.success = success; }
    
    public TmiPrograms getData() { return data; }
    public void setData(TmiPrograms data) { this.data = data; }
    
    public String getTimestamp() { return timestamp; }
    public void setTimestamp(String timestamp) { this.timestamp = timestamp; }
}

/**
 * Ingest operation result
 */
@JsonIgnoreProperties(ignoreUnknown = true)
class IngestResult {
    
    @JsonProperty("processed")
    private int processed;
    
    @JsonProperty("created")
    private int created;
    
    @JsonProperty("updated")
    private int updated;
    
    @JsonProperty("errors")
    private int errors;
    
    @JsonProperty("error_details")
    private List<String> errorDetails = new ArrayList<>();
    
    // Getters and setters
    public int getProcessed() { return processed; }
    public void setProcessed(int processed) { this.processed = processed; }
    
    public int getCreated() { return created; }
    public void setCreated(int created) { this.created = created; }
    
    public int getUpdated() { return updated; }
    public void setUpdated(int updated) { this.updated = updated; }
    
    public int getErrors() { return errors; }
    public void setErrors(int errors) { this.errors = errors; }
    
    public List<String> getErrorDetails() { return errorDetails; }
    public void setErrorDetails(List<String> errorDetails) { this.errorDetails = errorDetails; }
}
