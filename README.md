# WordPress Publishing Importer

A solid framework to import content and data (also e.g. subscriptions) from a traditional / legacy publishing industry system into WordPress and WooCommerce.

For each system and content type a different parser can be implemented to convert the content into WordPress posts, terms, and users.

Source systems and parsers are defined in a `config.json` file.

Source data is expected in files by default. Various formats are supported: CSV, JSON, XML (both one-entity-per-file and one-entity-per-element).

The framework is optimized for speed. Previously imported items are only imported again if their content has changed. Also, custom rules can be defined to force or omit updates for already imported data (e.g. when they were manually edited or sent to trash). Re-running the import on unchanged data only takes a few seconds to complete.

Imports are supposed to be run via WP-CLI:

```console
$ time wp --user=system publishing-importer import
Processed epaper 2000106 (ID 686545).
Cancelled subscription 648842.
Cancelled subscription 649075.
Cancelled subscription 652075.
Cancelled subscription 652082.
Cancelled subscription 652316.
Cancelled subscription 652317.
Success: Import completed.

real	0m1.739s
user	0m1.317s
sys	0m0.376s
```


## Parsers

### DPA News

Reads XML files in NITF format (News Industry Text Format) provided by the German national Deutsche Presse Agentur and converts them into WordPress posts. The DPA pushes the source files via FTP.

### alfamedia Subscriptions

Reads exported subscriptions from an XML file and converts them into WordPress user accounts and WooCommerce Subscriptions.

Required plugins:
- WooCommerce
- WooCommerce Subscriptions
- email-verification-for-woocommerce
- woocommerce-sequential-subscription-numbers


## Configuration options

Configuration is read from the following files:

1. `config.json` in the plugin folder

2. `.publishing_importer.config.json` in the site root folder (optional)

Identical keys in the second file are overwriting/replacing the keys in the first file.

Each Parser can define its own options as necessary. Only a few are required:


Key                   |Description|Data Type
----------------------|---|---
`publisher`           |**(required)** Name of the publisher; e.g.: `DPA`.|string
`system`              |**(required)** Name of the publishing system; e.g.: `weblines`.|string
`types`               | |object[]
&#8866; `parserClass` |**(required)** Class name of the parser class to process the data.|string
&#8866; `directory`   |**(required)** Folder in which source file(s) are found.|string
&#8866; `recursive`   |Whether to also discover source files in nested subdirectories.|bool
&#8866; `file`        |File in which source data is found. Specifying this triggers row processing.|string
&#8866; `media`       |Folder relative to `directory` where image/media files are located.|string
&#8866; `defaultImage`|Name of a default image to use if content has none.|string
&#8866; `keywords`    |Filter tags.|string[]
&#8866; `maxAge`      |Days to retain articles in the local filesystem.|integer
`defaultAuthor`       |**(required)** WordPress username to assign as author of imported content and data.|string
`trashedFolder`       |To avoid importing deleted posts again, the importer can keep track of the history. Specify a folder relative to the WordPress root folder in which to store the markers; e.g.: `files/dpa/trashed`|string
`uploadsPrefix`       |A prefix to prepend to imported image/media files to avoid name clashes or allow quick identification of third-party content; e.g.: `dpa-`|string
`apiKey`              |API key for parser implementation, if necessary.|string
