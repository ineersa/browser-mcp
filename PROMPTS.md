```
Use @CREATE.md
Here's the feature I want to build: [Describe your feature in detail]
Reference these files to help you: [Optional: @file1.py @file2.ts]
```

```
Now take @MyFeature-PRD.md and create tasks using @generate-tasks.md
```

```
Please start on task 1.1 and use @process-task-list.md
```

```
Use @CREATE.md

Motivation:
Models don't unsderstand correctly how to use this MCP tools, so we need to simplify it and make better suited for them.
Key painful moments for now are:
 - models don't scroll ever, they simply don't know how to use it
 - find requires page opening and models sometimes fail it
 - more parameters in tool calls more issues
 - optional parameters do mess
 
Search tool changes:
 - we need to get canonical URLs in response instead of links `【{URL???}†…】` or somehow else we should provide model clear definition of URL 
 - we should remove PAGE_ID completely from response
 - we need to update tool description and tests accordingly, modify fixtures too
 
As internal technical side we would need to implement URLs clearing and make \App\Service\BrowserState cache results by URL instead of PAGE_IDs.

Open result tool changes:
 - this should become open tool
 - it should have only 3 parameters, all required: URL, start_at_line (open page at ? default first line), number_of_lines (num_lines default 50)
 - again we have to remove PAGE_ID, all BrowserState caching would be via URL
 - we need to update tool description and tests accordingly, modify fixtures too
 
Find tool changes:
 - it should have 2 parameters: URL, regex as now
 - it should open page by URL and cache it in BrowserState if URL wasn't opened before
 - we need to update tool description and tests accordingly, modify fixtures too
 

```
