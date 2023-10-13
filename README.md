# MyBB Age Gate plugin

This plugin adds an age gate feature to your MyBB forum. 

It will look at user's birthdays to determine their age and will 
automatically place them into a designated usergroup if they do not meet 
the minimum age requirement.

Version 1.0.0:
* Add Age and Age_Gate fields to user database
* ACP settings include
  * Minimum age requirement
  * Usergroup selection for underage users
  * Grandfathering option for existing users
  * Grandfathering registration date cutoff

Note: This Plugin changes the usergroups of your users. 
It has some protections to prevent changes to admins and banned user accounts.
However, it is still recommended that you backup your database before using this plugin.

Current version lacks birthday validation, users with invalid birthdates are 
assumed to be 18 years old. This will be fixed in a future version.