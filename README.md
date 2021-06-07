# Pinehearst Labs Electoral Office Tool

## Requirements

* PHP >=5.3
* Mysql 5
* WCS 5.2+

## Installation

* Run all files in the `sql/` folder in your database of choice
* Copy `config/settings.default.ini` to `config/settings.ini` and set your options accodringly!
* We're using composer for our dependecy management. Check out https://getcomposer.org/ if you are not familiar.

## Change Log

### Version 1.4 (June 2021)

* added needsMaxVotes option to force usage of maximum available votes

### Version 1.3.1 (May 2021)

* fix votesPerChoice option
* upgrade f3 to 3.7.3
* remove Astor specific header link
* fix forum/wcs links

### Version 1.3 (April 2020)

* upgrade f3 to version 3.7
* reorganise files
* change compatibility to WCS 5.2

### Version 1.2

* auto searching database connection details for wbb
* auto searching cookie prefix
* added votesPerChoice option for elections, limiting the maximum votes per election choice

## Contact

Any questions? Contact us!
<https://us.astor.ws/forum/?thread/16145>
or
<https://us.astor.ws/?conversation-add/&userID=2342>
