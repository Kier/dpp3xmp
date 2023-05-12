# dpp3xmp
PHP utility to extract adjustment metadata from Canon Digital Photo Professional 3 recipes and output a corresponding XMP sidecar.

## Usage

### Running the utility

The utility has a single command to run:
```bash
php dpp3xmp.php /path/to/data
```
The `/path/to/data` refers to either a single RAW photo, or a directory containing RAW photos.

#### XMP Generation Mode

```bash
# example
php dpp3xmp.php ../photos/raw
```

When the path to a directory is provided, the utility will recursively scan the directory for Canon RAW photos containing DPP3 recipes. When a photo with a recipe is found, a corresponding XMP sidecar file will be written next to the photo.

If the recipe specifies a white balance, the utility will use white balance reference data for the corresponding camera if it has already been generated, and will proceed to build reference data if not. See the following section [White Balance Reference Mode](#white-balance-reference-mode) for details of how this works. After the data is generated, the utility will continue generating XMP files.

When scanning a large folder containing photos from multiple cameras, having the process pause each time white balance data is required may be inconvenient if you want to leave it running unattended. In this instance, you can preemptively generate the necessary white balance reference data for each camera prior to generating XMP files, using [White Balance Reference Mode](#white-balance-reference-mode).

Note: Each individual camera unit will need its own white balance reference data. For example, if you own two EOS 20D cameras, they can not share the same reference data.

#### White Balance Reference Mode

```bash
# example
php dpp3xmp.php ../photos/raw/IMG_4321.CR2
```

When the path to a single RAW photo is provided, or when called by the [XMP generation system](#xmp-generation-mode), the utility will generate and save white balance reference data for the camera that captured the specified photo.

The utility will begin by creating a directory in the `reference` folder next to the script itself, named according to the camera model and serial number. It will then fill that directory with reference photos having their white balance set to the color temperature equivalent to their file name, so 5200.CR2 will have color temperature 5,200 K etc. This may take a minute or two depending on disk access speed.

When the photo generation process is complete, you will be prompted to open the new directory in Digital Photo Professional 3. From there, you must select all images and run `File > Save` in order to have DPP3 generate color multipliers equivalent to the color temperature specified. This will take a few seconds.

After this is complete, you must type `ok` and [Enter] in the terminal window to tell the utility that the reference photos are ready. Over the next few moments, it will then read the multipliers and store the data. After this is complete, you may delete the directory containing the reference photos, as they are no longer required.

Note: After white balance reference data for a particular camera has been generated, it never needs to be done again unless you delete the corresponding `.json` file in the `cameras` directory.

### Using the Generated XMP Files

After XMP files have been generated, you can bring your Digital Photo Professional 3 recipes into Adobe Lightroom.

If your photos are already in your Lightroom catalog, simply select them all then run `Metadata > Read Metadata from Files` to bring in the edits imported from DPP3.

Alternatively, if you have not yet imported the RAW files into your Lightroom catalog, importing them will automatically import the adjustments specified in the XMP files.

## The Story

If you, like me, started shooting RAW with Canon EOS cameras in the early 2000s, there's a good chance that you did your RAW processing and editing with [Canon Digital Photo Professional](https://www.eos-magazine.com/articles/dpp/) (DPP), a utility that is part of the EOS software package supplied with your camera.

In its early versions, DPP was not particularly extensive in its functionality, nor was it much of a joy to use, but many photographers used it to adjust exposure and white balance in their photos. I personally have a library of several hundred thousand photos edited with DPP version 3.

Adjustments made in DPP are saved in the EXIF metadata of the file in what DPP calls a 'recipe'. Unfortunately, this recipe format is proprietary to DPP, and I am unaware of any other RAW processing software that attempts to make use of it. To make matters worse, Digital Photo Professional 3 is now legacy software and will not run at all on recent versions of macOS, making it very difficult to make further edits to photos originally edited with DPP3.

In 2009, I switched from DPP3 to Adobe Lightroom. While I could import my existing RAW photos into Lightroom, their recipes were ignored and they appeared in Lightroom in their un-edited state. In fact, I am unaware of **any** other RAW processing software that reads DPP3 recipes. Inexplicably, even Canon's own current version of Digital Photo Professional (version 4) ignores the adjustments and displays un-edited files. 

This utility aims to read the most important parts of a DPP3 recipe and output it in an XMP sidecar file that is readable by Adobe Lightroom, so that your photos can be imported with their adjustments intact.

## The Technical Stuff

The Digital Photo Professional 3 recipe for a photo can be read using the excellent [ExifTool by Philip Harvey](https://exiftool.org).

Some adjustments are very easy to translate, such as **exposure** adjustment, which is stored as a simple +/- ev figure.

Other settings require some degree of interpretation and translation, such as **contrast** adjustment, which DPP stores in a range of -4 to +4 compared to Lightroom's -100 to +100 range.

By far the most challenging translation is white balance.

### Why White Balance is Difficult

Lightroom, and many other RAW processors, provide a simple interface to allow white balance to be set as a [color temperature in Kelvin](https://en.wikipedia.org/wiki/Color_temperature). Digital Photo Professional 3 does the same, but this is not always how the adjustment is stored in the recipe. Specifically, if _Click white balance_ was used in DPP, as is often the case in professional photography when a photo of a grey card serves as reference for a shoot, the resulting value is not stored as a value in kelvin at all.

Instead, the DPP recipe stores a set of red, green and blue multipliers against which the color values of each pixel are multiplied to get the final color value. There is no foolproof algorithm to programmatically extract a color temperature from the multipliers stored in a DPP recipe. 

To complicate matters, the multipliers corresponding to a particular color temperature are unique to each camera. Not to each range, but to **each individual unit**. My three EOS-1D Mark III cameras each have different multiplier values for 5,200 K and for every other color temperature.

### The Solution

The solution used by this utility involves producing example photos with recipes setting particular color temperatures, then reading the corresponding multipliers from those reference photos and comparing them to the multipliers in a photo from which we want to extract an unknown color temperature. The reference image with multipliers closest to the subject photo is the winner, and we use the known color temperature specified in that reference photo. After the reference photos are produced and interrogated, the resulting color temperature data is stored against a unique identifier for the source camera, and the reference photos can be safely deleted.

By default, this utility will create reference photos with color temperatures from 2,500 K to 10,000 K in 50 K increments, so theoretically the results of comparing a subject photo to these reference photos will result in a color temperature diverging by less than 50 K from that originally set in DPP3. Theoretically. Accuracy could be improved by producing more reference photos but in my testing, the 50 K increment has been sufficient.

## Limitations

This utility currently handles the following adjustments from Digital Photo Professional 3 recipes:

- Exposure
- White balance / color temperature
- Contrast
- Saturation
- Sharpness

Notable omissions at this point include the _tint_ aspect of white balance, highlight and shadow adjustments and cropping information. 


## Requirements
* [PHP](https://www.php.net/downloads)
* [ExifTool by Phil Harvey](https://exiftool.org)
* A working version of [Canon Digital Photo Professional 3.x](https://support.usa.canon.com/kb/index?page=content&id=ART116547).