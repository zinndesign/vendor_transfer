# Using the Vendor Transfer script
The vendor transfer script is intended to automate file transfers for replica magazine content from Condé Nast, Hearst and Rodale to over a dozen digital vendors. The script is written in PHP, and uses a local SQLite database as its data source. The script is intended to be used as a **[folder action](https://developer.apple.com/library/content/documentation/LanguagesUtilities/Conceptual/MacAutomationScriptingGuide/WatchFolders.html)** so that files can be dropped into a folder to trigger the file upload process. The database structure and script logic are described below.

## Data source (pwxvendor.sqlite)
The pwxvendor SQLite database is a [normalized](https://en.wikipedia.org/wiki/Database_normalization) set of tables, meaning the data has been structured to minimize duplication of data. The database contains the following 8 tables:

* publishers
* brands
* vendors
* filetypes
* ftp
* naming_conventions
* date_regex
* brand2vendor

The last table is what's known as a __join__ table - meaning that it contains all brand/vendor combos, as well as the corresponding FTP info and expected files to be transferred.

For viewing and editing data, I would strongly recommend installing a free program named DB Browser for SQLite. This is a GUI program that allows you to open SQLite databases, browse the data, make updates, and run SQL queries. The installation package for Mac OS X can be [downloaded from this GitHub page](https://github.com/sqlitebrowser/sqlitebrowser/releases).

## PHP Script (vendor_transfer.php)
The vendor transfer script runs on the command line. The dependencies are as follows:

* **PHP** (part of the standard install of Mac OS X)
* **sshpass** ([requires installation](https://gist.github.com/arunoda/7790979))

Each brand that requires replica file transfers will have its own folder, named using the **brand code** (2 letters for Condé and Rodale, 3 for Hearst). When files are dropped into a brand's folder, the following process is kicked off:

* The script checks the directory for all files with relevant extensions (pdf, xls, xlsx, and zip). A PDF file is required at a minimum, so if none is present, the script ceases execution with an error message.
* Based on the brand code of the enclosing folder, the script queries the database to find all files and corresponding naming conventions that are required by the vendors the brand distributes to. If any of the required files are missing, the script dies with an error message.
* Assuming all required files are present, the script queries the database again to get all vendors the brand sends to, as well as their FTP info and the type(s) of files they receive.
* The script then loops through the list of vendors and attempts to connect and transfer files to each one. The functionality differs depending on whether the connection is FTP or SFTP:
	* If FTP, the built-in PHP functions can be used. If the connection fails (bad URL) or the credentials are rejected (bad username and/or password), the script logs the result and moves on to the next vendor.
	* If SFTP, the script uses the command line **sftp** program. This introduces some complications, which are detailed further below. As with FTP, if the URL or credentials are bad, the script logs the results and continues.
* Once all vendor entries have been processed, the script checks to see if there were any failures. If not, all of the files are moved into a sub-folder named `processed` to ensure they are not re-processed.
* As noted in several of the steps above, relevant information is written to a log file as the script runs. This file is name **results.log** and is found in the `processed` sub-folder for each brand.

## Workarounds for SFTP
The sftp command-line utility is an interactive program. Since we are running it in an automated script, some workarounds are required:

* SFTP expects a connection request, followed by password input + Enter. To address this we use the program **sshpass**, which allows us to send the password as a parameter. For example:
`sshpass -f "/private/tmp/PWGP6QTo2" sftp -oBatchMode=no -b /private/tmp/PW7aCRVNe username@url.to.sftp.site`
Note that for security, we use the `-f` option to pull the password from a temp file, which is then deleted.
* As we do with the password, we also write all the commands we are going to send to the sftp session to a temp file, and read them from there (known as _batch mode_). This allows us to batch any directory change, transfer and exit commands, and send them all together.
* The last workaround is capturing the output from the sftp batch execution to identify errors. We use the PHP `exec` function to capture the output, then check the output string for the following phrases that indicate a failure:
	* timed out
	* not found
	* refused

## Setting up the folder action
On Mac OS X, the [Automator](https://developer.apple.com/library/content/documentation/AppleApplications/Conceptual/AutomatorConcepts/Automator.html) program allows you to trigger "actions" when a file is added to a folder (often referred to as a "hot folder"). This repository contains a directory named `vendor_transfer.workflow` which can be opened in Automator to create a _service_ that can be applied to folders in the Finder program.

The vendor_transfer folder action triggers a shell script that is run whenever a new file is added to the folder. The code for the action is below:

```
for f in "$@"
do
	/usr/bin/php ~/Desktop/vendor_transfer.php "$f"
done
```
The only parts that may need to be altered are the paths. The path to PHP can be determined by entering `which php` in a Terminal window. The path to the `vendor_transfer.php` script is arbitrary, depending on where the user chooses to save it. The script must exist at the path specified or the action will fail. Futhermore, the pwxvendor.sqlite database file must always be in the same location as the PHP script.

Once its set up, the action can be applied to a folder by right-clicking on it in Finder and selecting `Services > Folder Actions Setup`. The `vendor_transfer.workflow` action should be available in the list of actions that pops up.

## Cloning or downloading the repository
There are a couple of options for copying these files to your local machine. The repository page on GitHub has a "Download or Clone" button, which allows you to either:

* Download a zip containing all of the files in the repository; or
* Clone the repository using Git: `git clone https://github.com/zinndesign/vendor_transfer.git`

The advantage to cloning the repository is that it maintains a link to the remote source - so if changes are made, a simple call to `git pull` will update the local files.

However, if the script will not be updated frequently - or if the intention is to move it to a different folder - downloading a zip will suffice.

_Last update: 03/05/2018_