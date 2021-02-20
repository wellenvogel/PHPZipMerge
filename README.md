# ZipStreamMerge

Based on the work of [Grandt/PHPZipMerge](https://github.com/Grandt/PHPZipMerge)

Combine and stream the contents of multiple existing Zip streams, as a single file, *without* recompressing the data within.
The code was modified to allow the zip files to be remote urls (i.e. having streams that are not seekable).
When reading a zip stream there is a moving buffer always containing the last nnn bytes (default 2000000) of
the stream.
So at the end of a stream we have the central directory in the buffer (prerq: the buffer must be large enough).
This way we can get all the entries from the directory and combine them.
As the zip standard allows for multiple central directories (only the last one is used) this way
we can easily append multiple zip streams.

Additionally you can add files to the target zip stream. Due to limitations in php 5 (no stream deflate)
those files are added uncompressed. This work is based on [maennchen/ZipStream-PHP](https://github.com/maennchen/ZipStream-PHP).

# Usage
See the usage examples in [tests](tests).
In [proxy.php](proxy.php) a simple php proxy is implemented that allows to combine one zip archive and
one simple file into one stream.
With [index.html](index.html) a minimalistic front end for testing is available.



