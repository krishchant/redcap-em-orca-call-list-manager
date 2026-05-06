# ORCA Call List Manager (REDCap External Module)

### Purpose

The purpose of this module is to have a sortable data table to show patient status along the recruitment process.

### Disclaimer

> This is a custom version of the original module, to support multiple configurations.

This module does not yet support arms.

## Features

- Customizable data table
- Customizable filtering

## Testing & Validation

- REDCap
    - v17.0.0
- PHP
    - v8.3.21

> **NOTE:** These are the most recent versions tested.  Previous versions should still be supported unless stated otherwise.

## Permissions

- none

## Options

- Color coded list
    -In the primary filter field select your variable that will drive the color coding. There are 6 possible colors based on these coding values.  Values must be coded with numerics 
        - 1, (Green)
        - 2, (Blue)
        - 3, (Red)
        - 4, (Purple)
        - 5, (Yellow)
        - 6, (Orange)
    - You can add more options however, the colors will not appear for additional values.
    - You can add any value you want after the comma to utilized the comma.  For example, if you like 1, Appointment scheduled all the values of Appointment Scheduled will be green.
- Viewable contact attempts (Display the contact attempts by time of day)
    - To get the contact attempts to show select the date field that will be used for displaying contact attempts. This must be a date/time field and must exist on a repeating instrument
    - You can configure the time range of the contact attempts (e.g. 00:00-11:59)
- Display the title of the table:
    - Name the table that fits your project
- Specify the number of results per page to display in the table
    - 10 (default), 25, 50, 100, 150, 200, 500
- Additional filters for call list:
    - Allows to filter the whole data table by one variable, this must be a dropdown.
- Specify time ranges for contact attempts
    - time ranges for am, pm and evening (e.g. 18:00-23:59)
- Select the fields to display
    - Select the variables from your project to display in the table.
    - Columns display in the order they are configured
- Display field sorting
    - To flag a display field for sorting, check the checkbox.
    - Specify a sort direction of Ascending or Descending
    - Specify a sort priority (order columns are sorted)
        - Must be numeric and greater than 0
        - Non-unique priority numbers will sort based on order in the config
    - An alert will be displayed if sort configuration is incorrect

## Considerations

- Repeating instruments and events will show the information from the latest form.
- Projects with significant record counts will increase load times and may not render (10,000+ record counts)
- If you identify any issues, please submit an issue on this GitHub repo or make a post on the forums and tag (@chris.kadolph or @leila.deering or @krishna.upadhyay)
- Your project should be created first before enabling the module.  