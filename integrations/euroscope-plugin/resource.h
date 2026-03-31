#ifndef RESOURCE_H
#define RESOURCE_H

// Tag item function IDs (registered with EuroScope)
#define TAG_ITEM_EDCT           1
#define TAG_ITEM_CTOT           2
#define TAG_ITEM_TMI_STATUS     3
#define TAG_ITEM_AMAN_SEQ       4
#define TAG_ITEM_AMAN_DELAY     5
#define TAG_ITEM_TMI_DELAY      6
#define TAG_ITEM_CDM_STATUS     7
#define TAG_ITEM_FLOW_STATUS    8

// Tag function IDs (actions triggered by clicking tag items)
#define TAG_FUNC_SHOW_DETAIL    100
#define TAG_FUNC_ACK_TMI        101

// Timer IDs
#define TIMER_SWIM_POLL         1001
#define TIMER_FSD_RECONNECT     1002

// Poll intervals (ms)
#define SWIM_POLL_INTERVAL      15000
#define FSD_RECONNECT_INTERVAL  30000

#endif // RESOURCE_H
