<?php

class Dpp3Xmp
{
	const CREATE_FILES = true;

	const REFERENCE_PATH = '/reference/';

	/*
	 * DPP stores highlight and shadow adjustments in a range -5 to +5
	 * while XMP stores -100 to +100. We can therefore multiply the DPP
	 * value by 20 to get the XMP value. However, Lightroom's shadow and
	 * highlight process is not particularly comparable to the DPP approach,
	 * so you may want to disable the translation. If so, set both shadow
	 * and highlight multipliers to 0.
	 */
	const SHADOW_MULTIPLIER = 20;
	const HIGHLIGHT_MULTIPLIER = 20;

	/*
	 * When applying a filter to a monochrome image, DPP does not allow
	 * fine adjustment to the filter amount. The equivalent XMP adjustment
	 * ranges from -100 to +100, but the effect is not identical. Choose a
	 * value to correspond to the DPP amount here.
	 */
	const MONO_FILTER_STRENGTH = 50;

	const VERSION = '1.0.0 Beta';

	public static array $rawTypes = ['CRW', 'CR2', 'CR3'];

	/** @var array */
	protected array $cameras = [];

	/** @var null|SplFileInfo  */
	protected SplFileInfo|null $file = null;

	protected $exif;

	protected $dir = null;

	protected $hasEdits = false;

	protected int $tempMin;
	protected int $tempMax;
	protected int $tempStep;

    public function __construct($dir, int $tempStep = 50, int $tempMin = 2500, int $tempMax = 10000)
    {
		$this->dir = $dir;

		$this->tempMin = $tempMin;
		$this->tempMax = $tempMax;
		$this->tempStep = $tempStep;

		$this->write();
		$this->write("Canon Digital Photo Professional 3.x recipe to XMP converter " . self::VERSION);
    }

	public function convertDPPtoXMP($root): bool
	{
		if (empty($root) || !is_dir($root))
		{
			$this->write("Root folder {$root} not found.");
			return false;
		}

		$di = new \RecursiveDirectoryIterator($root);
		$iterator = new \RecursiveIteratorIterator($di, RecursiveIteratorIterator::SELF_FIRST);
		$lastPath = '';

		/** @var SplFileInfo $file */
		foreach ($iterator AS $file)
		{
			if (!$file->isFile() || !in_array(strtoupper($file->getExtension()), self::$rawTypes))
			{
				continue;
			}

			$path = $file->getPathInfo();
			if ($path != $lastPath)
			{
				$this->write();
				$this->write($path . '/');
				$lastPath = $path;
			}

			$this->writeFileIntro($file);

			$xmp = $this->getXMP($file, $rggb, $wbAdj, $wb, $k, $exp);

			if (!is_string($xmp))
			{
				$this->write('(no recipe)', 1, '');
				continue;
			}

			$this->write(($k ? number_format($k) . ' K' : ' (Auto)') . ' / ' . $wb . ($exp ? "; {$exp}ev" : ''), 1, '');

			$fp = fopen($file->getPathInfo() . '/' . $file->getBasename('.' . $file->getExtension()) . '.xmp', 'w');
			fwrite($fp, $xmp);
			fclose($fp);
		}

		$this->write();
		$this->write('All done.');

		return true;
	}

	public function buildReference(SplFileInfo $file, $exif = null, &$error = ''): ?string
	{
		if (is_null($exif))
		{
			$exif = $this->getExif($file);
		}

		if (!$this->hasRecipe($exif))
		{
			$error = "No DPP3 recipe in {$file->getFilename()}, unable to continue.";
			return false;
		}

		$this->file = $file;

		$this->write(sprintf("Building white balance reference for %s%s serial %s",
			$exif->CanonModelID,
			($exif->OwnerName ? " ({$exif->OwnerName})" : ''),
			$exif->SerialNumber
		), 2);

		$folder = $this->dir . self::REFERENCE_PATH . '/' . $this->getCameraId($exif);
		$extension = strtoupper($file->getExtension());

		if (!is_dir($folder))
		{
			mkdir($folder);
		}

		foreach (glob("{$folder}/*.{$extension}") AS $filename)
		{
			unlink($filename);
		}

		$this->write("Generating reference photos...");

		$tempFile = sys_get_temp_dir() . '/' . $this->getCameraId($exif) . '.' . $file->getExtension();
		copy($file->getPathname(), $tempFile);

		for ($temperature = $this->tempMin; $temperature <= $this->tempMax; $temperature += $this->tempStep)
		{
			$pathname = $tempFile;
			$newPathname = "{$folder}/{$temperature}.{$extension}";
			if (file_exists($newPathname))
			{
				unlink($newPathname);
			}

			`exiftool -CanonVRD:WhiteBalanceAdj=Kelvin -CanonVRD:WBAdjColorTemp={$temperature} {$tempFile}`;

			rename($tempFile, $newPathname);
			rename("{$tempFile}_original", $tempFile);

			$this->writeTemperatureProgress($temperature);
		}

		do
		{
			$this->write('', 2);
			$this->write("Open the folder " . realpath($folder) . " in DPP3,");
			$this->write("select all reference photos and run File > Save.", 2);
			$ok = $this->read("Type 'ok' when photos are saved in DPP3: ");
		}
		while (strtoupper($ok) != 'OK');
		$this->write();

		$json = [
			'CanonModelID' => $exif->CanonModelID,
			'OwnerName' => $exif->OwnerName,
			'SerialNumber' => $exif->SerialNumber,
			'data' => []
		];

		$this->write("Reading white balance data...");

		for ($temperature = $this->tempMin; $temperature <= $this->tempMax; $temperature += $this->tempStep)
		{
			$photo = new SplFileInfo("{$folder}/{$temperature}.{$extension}");
			$photoExif = $this->getExif($photo);

			if ($photoExif->WhiteBalanceAdj != 'Kelvin' || $photoExif->WBAdjColorTemp != $temperature)
			{
				Dpp3Xmp::write("Photo {$photo->getFilename()} should be set to color temperature = {$temperature}k in DPP3, but is actually set to {$photoExif->WBAdjColorTemp}k.");
				exit;
			}

			$this->writeTemperatureProgress($temperature);

			$json['data'][$temperature] = $this->getMultipliersFromRGGB($photoExif->WBAdjRGGBLevels);
		}

		$jsonFile = "{$this->dir}/cameras/{$this->getCameraId($exif)}.json";
		$fp = fopen($jsonFile, 'w');
		$jsonText = preg_replace('/("\d{4,5}":\[)/', "\n\$1", json_encode($json));
		fwrite($fp, $jsonText);
		fclose($fp);

		$this->write('', 2);
		$this->write("White balance reference data for {$exif->CanonModelID} serial {$exif->SerialNumber} generated.");
		$this->write("You may now delete " . realpath($folder), 2);

		return $jsonFile;
	}

	protected function writeTemperatureProgress($temperature)
	{
		$done = ($temperature - $this->tempMin) / $this->tempStep + 1;
		$total = ($this->tempMax - $this->tempMin) / $this->tempStep + 1;

		$this->write($this->progress($done, $total, sprintf("\t%' 5d.%s ", $temperature, $this->file->getExtension())), 0, '');
	}

    public function getXMP(SplFileInfo $file, &$WBAdjRGGBLevels = '', &$WhiteBalanceAdj = '', &$whiteBalance = '', &$kelvin = '', &$exposure = ''): ?string
    {
		$this->file = $file;
        $this->exif = $this->getExif($file);
		$this->hasEdits = false;

        if (!$this->hasRecipe($this->exif))
        {
            // no recipe data - nothing to do
            return null;
        }

		$WBAdjRGGBLevels = $this->exif->WBAdjRGGBLevels;
		$WhiteBalanceAdj = $this->exif->WhiteBalanceAdj;
        $whiteBalance = $this->getWhiteBalance($kelvin);
		$checkMark = $this->getCheckMark($checkMarkValue);
		$toneCurvesXml = $this->getToneCurves($toneCurvePoints);
		$attributes =
			$this->getRating($rating) .
			$this->getTemperature($kelvin) .
			$this->getExposure($exposure) .
			$this->getContrast($contrast) .
			$this->getHighlight($highlight) .
			$this->getShadow($shadow) .
			$this->getSaturation($saturation) .
			$this->getSharpness($sharpness) .
			$this->getCrop($cropped) .
			$this->getRotation($tiffOrientation) .
			$this->getPictureStyle($crsName, $crsConvertToGrayscale);

	    if ($checkMark)
	    {
		    $checkMarkXml = '
			<dc:subject><rdf:Bag><rdf:li>' . $checkMark . '</rdf:li></rdf:Bag></dc:subject>';
	    }

		if ($crsConvertToGrayscale == 'True')
		{
			$greyscaleXml = '
			<crs:Look>
				<rdf:Description crs:Name="' . $crsName . '">
					<crs:Parameters>
						<rdf:Description crs:ConvertToGrayscale="' . $crsConvertToGrayscale . '">
						</rdf:Description>
					</crs:Parameters>				
				</rdf:Description>
			</crs:Look>';
		}

		// TODO: rotation

		if (!$this->hasEdits)
		{
			// no exposure tweaks, no white balance adjustments - nothing to do
			return null;
		}

		return '<x:xmpmeta xmlns:x="adobe:ns:meta/" x:xmptk="Adobe XMP Core 7.0-c000 1.000000, 0000/00/00-00:00:00">
	<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">
		<rdf:Description rdf:about="" 
			xmlns:xmp="http://ns.adobe.com/xap/1.0/"
			xmlns:tiff="http://ns.adobe.com/tiff/1.0/"
			xmlns:crs="http://ns.adobe.com/camera-raw-settings/1.0/"
			xmlns:dc="http://purl.org/dc/elements/1.1/"		
			WBAdjRGGBLevels="' . $WBAdjRGGBLevels . '"			
			crs:Version="15.3"
			crs:ProcessVersion="11.0"
			crs:WhiteBalance="' . $whiteBalance . '"' . $attributes . '
			crs:LensProfileEnable="' . ($cropped ? 0 : 1) . '"
			crs:ToneCurveName2012="Linear"
			crs:HasSettings="True"
			crs:AlreadyApplied="False">' . $toneCurvesXml . ($greyscaleXml ?? '') . ($checkMarkXml ?? '') . '
		</rdf:Description>
	</rdf:RDF>
</x:xmpmeta>';
		
    }

    public function getExif(SplFileInfo $file)
    {
		$args = '-SerialNumber -OwnerName -ExifImageHeight -ExifImageWidth -Rating -CanonModelID -CanonVRD:all';
		$args = '-all -CanonVRD:all';
        $json = `exiftool -j {$args} "{$file->getPathName()}"`;
        return json_decode($json)[0];
    }

	protected function hasRecipe($exif): bool
	{
		return isset($exif->VRDVersion);
	}

    protected function getClosestKelvin(): int|null
    {
        if (!isset($this->exif->WBAdjRGGBLevels))
        {
            return null;
        }

        $recipeMultipliers = $this->getMultipliersFromRGGB($this->exif->WBAdjRGGBLevels);

        $minDiff = PHP_FLOAT_MAX;
        $closestKelvin = null;

        foreach ($this->getCameraMultipliers() AS $kelvin => $refMultipliers)
        {
            $rgb = 0;

            for ($i = 0; $i <= 2; $i++)
            {
                $rgb += pow($recipeMultipliers[$i] - $refMultipliers[$i], 2);
            }

            $diff = sqrt($rgb);

            if ($diff < $minDiff)
            {
                $minDiff = $diff;
                $closestKelvin = $kelvin;
            }

			// TODO: abandon if we start to get a bigger difference, indicating that we are getting further away?
        }

        return $closestKelvin + 0;
    }

    public function getMultipliersFromRGGB(string $exifString): array
    {
        $rggb = array_map(function($value)
        {
            return intval($value);
        }, explode(' ', $exifString));

        return [$rggb[0], $rggb[1], $rggb[3]];
    }
	protected function getCameraMultipliers(): array
	{
		$cameraId = $this->getCameraId($this->exif);

		if (!isset($this->cameras[$cameraId]))
		{
			$file = "{$this->dir}/cameras/{$cameraId}.json";

			if (!file_exists($file))
			{
				$camera = "{$this->exif->CanonModelID} serial {$this->exif->SerialNumber}";

				$this->write('White balance reference required...', 2, '');

				$file = $this->buildReference($this->file, $this->exif, $error);
				if ($error)
				{
					$this->write($error);
					exit;
				}

				$this->writeFileIntro($this->file);
			}

			$this->cameras[$cameraId] = $this->getCameraMultipliersFromFile($file);
		}

		return $this->cameras[$cameraId];
	}

    protected function getCameraMultipliersChoice(): array
    {
        $cameraId = $this->getCameraId($this->exif);

        if (!isset($this->cameras[$cameraId]))
        {
            $file = "{$this->dir}/cameras/{$cameraId}.json";

            if (!file_exists($file))
            {
	            $camera = "{$this->exif->CanonModelID} serial {$this->exif->SerialNumber}";
				$this->write(" Unknown camera " . $camera, 2, '');

				do
				{
					$this->write();

					$knownFiles = $this->getKnownCameraFiles();

					foreach ($knownFiles as $i => $data)
					{
						$this->write(sprintf("%' 3d) %s", $i + 1, $data['name']));
					}

					$this->write();

					$this->write("Type '0' to generate white balance data for {$camera},");
					$this->write("or select one of the cameras above to use as a substitute for {$camera},");
					$choice = trim($this->read("or type 'r' to scan available cameras again: "));
				}
				while ($choice !== '0' && !key_exists(intval($choice) - 1, $knownFiles));

				if ($choice === '0')
				{
					$file = $this->buildReference($this->file, $this->exif, $error);
					if ($error)
					{
						$this->write($error);
						exit;
					}
				}
				else
				{
					$file = $knownFiles[intval($choice)]['file'];
				}

				$this->write('- ' . $this->file->getPathname(), 0);
            }

			$this->cameras[$cameraId] = $this->getCameraMultipliersFromFile($file);
        }

        return $this->cameras[$cameraId];
    }

    protected function getKnownCameraFiles()
    {
        $dirIterator = new \DirectoryIterator("{$this->dir}/cameras");
        $iterator = new IteratorIterator($dirIterator);

        $files = [];

        /** @var SplFileInfo $file */
        foreach ($iterator AS $file)
        {
			if ($file->isFile() && $file->getExtension() == 'json')
			{
				$data = json_decode(file_get_contents($file->getPathname()));
				$files[] = [
					'file' => $file->getPathname(),
					'name' => "{$data->CanonModelID} serial {$data->SerialNumber}"
				];
			}
        }

        return $files;
    }

    public function getCameraId($exif): string
    {
        $id = preg_replace('/[^a-z0-9_-]/i', '-', $exif->CanonModelID) . '.' . $exif->SerialNumber;
		return preg_replace('/-+/', '-', $id);
    }

	protected function getCameraMultipliersFromFile(string $path): array
	{
		// TODO: error handling
		return json_decode(file_get_contents($path), true)['data'];
	}

    protected function getWhiteBalance(&$kelvin): string
    {
        switch ($this->exif->WhiteBalanceAdj)
        {
            case 'Cloudy': // $kelvin = 6000;
            case 'Daylight': // $kelvin = 5200;
            case 'Flash': // $kelvin = 6000;
            case 'Fluorescent': // $kelvin = 4000;
            case 'Shade': // $kelvin = 7000;
            case 'Tungsten': // $kelvin = 3200;
	        case 'Manual (Click)':
	            $this->hasEdits = true;
                $kelvin = $this->getClosestKelvin();
                return ($this->exif->WhiteBalanceAdj == 'Manual (Click)') ? 'Custom' : $this->exif->WhiteBalanceAdj;

            case 'Kelvin':
	            $this->hasEdits = true;
                $kelvin = $this->exif->WBAdjColorTemp;
                return 'Custom';

            case 'Shot Settings':
                $kelvin = null;
                return 'As Shot';

            default:
                return 'Auto';
        }
    }

    protected function addSymbol($value): string
    {
        return ($value > 0 ? '+' : '') . $value;
    }

    protected function getAttribute($attribute, $value): string
    {
        return "\n\t\t\t{$attribute}=\"{$value}\"";
    }

	protected function getAttributes(array $attributes): string
	{
		$output = '';

		foreach ($attributes AS $attribute => $value)
		{
			$output .= $this->getAttribute($attribute, $value);
		}
		return $output;
	}

    protected function getTemperature(&$value)
    {
        if (is_integer($value))
        {
	        $this->hasEdits = true;

            return $this->getAttribute('crs:Temperature', $value) . $this->getAttribute('crs:Tint', 0);
        }

        return '';
    }

    protected function getExposure(&$value): string
    {
        if (isset($this->exif->RawBrightnessAdj))
        {
	        $this->hasEdits = true;

            $value = $this->exif->RawBrightnessAdj + 0;
            $value = $this->addSymbol($value);

            return $this->getAttribute('crs:Exposure2012', $value);
        }

        return '';
    }

    protected function getContrast(&$value = null): string
    {
        if (isset($this->exif->CameraRawContrast))
        {
	        $this->hasEdits = true;

            $value = $this->exif->CameraRawContrast + 0;
            $value = $this->addSymbol($value / 4 * 100);

            return $this->getAttribute('crs:Contrast2012', $value);
        }

        return '';
    }

	protected function getHighlight(&$value = null): string
	{
		if (isset($this->exif->StandardRawHighlight) && $this->exif->StandardRawHighlight)
		{
			$this->hasEdits = true;

			// XMP wants a value between -100 - 1000
			$value = $this->exif->StandardRawHighlight * self::HIGHLIGHT_MULTIPLIER;

			return $this->getAttribute('crs:Highlight', $value);
		}

		return '';
	}

	protected function getShadow(&$value = null): string
	{
		if (isset($this->exif->StandardRawShadow) && $this->exif->StandardRawShadow)
		{
			$this->hasEdits = true;

			// XMP wants a value between -100 - 1000
			$value = $this->exif->StandardRawShadow * self::SHADOW_MULTIPLIER;

			return $this->getAttribute('crs:Shadow', $value);
		}

		return '';
	}

	protected function getPictureStyle(&$crsName = 'Adobe Color', &$crsConvertToGrayscale = 'No'): string
	{
		switch ($this->exif->PictureStyle)
		{
			case 'Monochrome':
			{
				$this->hasEdits = true;

				$crsName = 'Adobe Monochrome';
				$crsConvertToGrayscale = 'True';

				$params = ['crs:Clarity2012' => '+8'];

				switch ($this->exif->MonochromeFilterEffect)
				{
					case 'Yellow':
					case 'Orange':
					case 'Red':
					case 'Green':
					{
						$params["crs:GrayMixer{$this->exif->MonochromeFilterEffect}"] = $this->addSymbol(self::MONO_FILTER_STRENGTH);
						break;
					}

					case 'None':
					default:
						break;
				}

				return $this->getAttributes($params);
			}

			default:
				return '';
		}
	}

    protected function getSaturation(&$value = null): string
    {
        if (isset($this->exif->CameraRawSaturation))
        {
	        $this->hasEdits = true;

            $value = $this->exif->CameraRawSaturation + 0;
            $value = $this->addSymbol($value / 4 * 100);

            return $this->getAttribute('crs:Saturation', $value);
        }

        return '';
    }

    protected function getSharpness(&$value = null): string
    {
        if (isset($this->exif->CameraRawSharpness))
        {
	        $this->hasEdits = true;

            // 0 -> 10 to 0 -> 150
            $value = $this->exif->CameraRawSharpness + 0;
            $value = $value / 10 * 150;

            return $this->getAttribute('crs:Sharpness', $value);
        }

        return '';
    }

	protected function getCrop(&$cropped = null): string
	{
		if (isset($this->exif->CropActive) && $this->exif->CropActive === 'Yes')
		{
			$this->hasEdits = true;
			$cropped = true;

			$params = $this->getCropFromExif($this->exif, $pixelValues);
			return $this->getAttributes($params);
		}

		return '';
	}

	public function getCropFromExif($exif, &$pixelValues = []): array
	{
		return $this->getCropParameters(
			$exif->AngleAdj,
			$exif->CropLeft, $exif->CropTop,
			$exif->CropWidth, $exif->CropHeight,
			$exif->ExifImageWidth, $exif->ExifImageHeight,
			$pixelValues
		);
	}

	public function getCropParameters($angleDegrees, $dppCropX, $dppCropY, $cropWidth, $cropHeight, $imageWidth, $imageHeight, &$pixelValues = []): array
	{
		if ($angleDegrees)
		{
			/*
			 * DPP stores its rotated crop data as though the entire image was rotated and the canvas expands
			 * to fit the rotated image. The top/left coordinates are relative to the expanded canvas.
			 * XMP stores rotation as the location of the top-left and bottom-right corners of a rectangle
			 * that is rotated within the original image.
			 * This code converts from DPP to XMP.
			 */
			$angle = deg2rad($angleDegrees);

			// get the size of the expanded canvas used by DPP
			$expandedWidth = $imageWidth * abs(cos($angle)) + $imageHeight * abs(sin($angle));
			$expandedHeight = $imageWidth * abs(sin($angle)) + $imageHeight * abs(cos($angle));

			// find the center of the expanded canvas
			$centerX = 0.5 * $expandedWidth;
			$centerY = 0.5 * $expandedHeight;

			// find out how much the canvas has expanded at each edge
			$expansionX = 0.5 * ($expandedWidth - $imageWidth);
			$expansionY = 0.5 * ($expandedHeight - $imageHeight);

			// get the position of the crop start relative to the center
			$relativeX = $dppCropX - $centerX;
			$relativeY = $dppCropY - $centerY;

			// rotate the crop start back around the image center, then subtract the canvas expansion amounts
			$cropLeft = $relativeX * cos(-$angle) - $relativeY * sin(-$angle) + $centerX - $expansionX;
			$cropTop = $relativeX * sin(-$angle) + $relativeY * cos(-$angle) + $centerY - $expansionY;

			// rotate a point at cropWidth, cropHeight back around the origin, then shift relative to the crop start
			$cropRight = $cropWidth * cos(-$angle) - $cropHeight * sin(-$angle) + $cropLeft;
			$cropBottom = $cropWidth * sin(-$angle) + $cropHeight * cos(-$angle) + $cropTop;
		}
		else
		{
			$cropTop = $dppCropY;
			$cropLeft = $dppCropX;
			$cropBottom = $dppCropY + $cropHeight;
			$cropRight = $dppCropX + $cropWidth;
		}

		$pixelValues = [
			'origin' => "$dppCropX, $dppCropY",
			'size' => "$cropWidth x $cropHeight",
			'top' => $cropTop,
			'left' => $cropLeft,
			'bottom' => $cropBottom,
			'right' => $cropRight,
			'width' => $cropRight - $cropLeft,
			'height' => $cropBottom - $cropTop
		];

		// XMP wants a figure between 0-1 to represent position within the image
		return [
			'crs:CropTop' => $cropTop / $imageHeight,
			'crs:CropLeft' => $cropLeft / $imageWidth,
			'crs:CropBottom' => $cropBottom / $imageHeight,
			'crs:CropRight' => $cropRight / $imageWidth,
			'crs:CropAngle' => -$angleDegrees,
			'crs:HasCrop' => 'True'
		];
	}

	protected function getRating(&$value = null): string
	{
		if (isset($this->exif->Rating) && $this->exif->Rating)
		{
			$this->hasEdits = true;

			$value = $this->exif->Rating;
			return $this->getAttribute('xmp:Rating', $value);
		}

		return '';
	}

	protected function getCheckMark(&$value = null): string
	{
		if (isset($this->exif->CheckMark))
		{
			$check = $this->exif->CheckMark2 ?? $this->exif->CheckMark;

			if ($check !== 'Clear')
			{
				$this->hasEdits = true;

				$value = $check;
				return "DPP3:CheckMark={$value}";
			}
		}

		return '';
	}

	protected function getRotation(&$tiffOrientation = null): string
	{
		$rotation = intval($this->exif->Rotation ?? 0);
		$tiffOrientation = $this->getTiffOrientation($rotation);

		if ($rotation != $this->getOrientationDegrees($this->exif->Orientation ?? 'Horizontal (normal)'))
		{
			$this->hasEdits = true;

			return $this->getAttribute('tiff:Orientation', $tiffOrientation);
		}

		return '';
	}

	protected function getOrientationDegrees($orientationString): int
	{
		if ($orientationString == 'Horizontal (normal)')
		{
			return 0;
		}

		if (preg_match('/Rotate (\d+) CW/', $orientationString, $match))
		{
			return intval($match[1]);
		}

		return 0;
	}

	protected function getTiffOrientation($degrees): ?int
	{
		switch ($degrees)
		{
			case 0: return 1;
			case 90: return 6;
			case 180: return 3;
			case 270: return 8;

			// unknown degrees...
			default: return null;
		}
	}

	protected function getToneCurves(&$points = [])
	{
		$curves = [];
		$xml = '';

		if (isset($this->exif->ToneCurveActive) && $this->exif->ToneCurveActive === 'Yes')
		{
			$this->hasEdits = true;

			foreach (['RGB', 'Red', 'Green', 'Blue'] AS $color)
			{
				$points = $this->getToneCurve($color);

				if ($points)
				{
					$name = $color != 'RGB' ? $color : '';
					$xml .= "
				<crs:ToneCurvePV2012{$name}>
					<rdf:Seq>";
					foreach ($points as $point)
					{
						$xml .= "
					<rdf:li>{$point[0]}, {$point[1]}</rdf:li>";
					}

					$xml .= "
					</rdf:Seq>
				</crs:ToneCurvePV2012{$name}>";
				}
			}
		}

		return $xml;
	}

	protected function getToneCurve($color)
	{
		$pointsProp = $color . 'CurvePoints';
		$points = [];

		preg_match_all('/\((\d+),(\d+)\)/', $this->exif->$pointsProp, $matches, PREG_SET_ORDER);
		foreach ($matches as $match)
		{
			$points[] = [$match[1], $match[2]];
		}

		return $points;
	}


	public function write($text = '', $newLines = 1, $prefix = "\t"): void
	{
		print ($text ? $prefix : '') . $text . str_repeat(PHP_EOL, $newLines);
	}

	public function writeFileIntro(SplFileInfo $file): void
	{
		$this->write(sprintf('- %s -- ', $file->getFilename()), 0);
	}

	protected function progress($done, $total, $info = '', $width = 50, $off = '_', $on = '#'): string
	{
		$perc = round(($done * 100) / $total);
		$bar = round(($width * $perc) / 100);

		if ($bar > $width)  // Catch overflow where done > total
		{
			$bar = $width;
		}

		return sprintf("%s[%s%s] %3.3s%% %s/%s\r",
			$info,
			str_repeat( $on, $bar),
			str_repeat( $off, $width-$bar),
			$perc,
			$done,
			$total
		);
	}   //*** progress_bar() ****/

	public function read($text): bool|string
	{
		return readline("\t{$text}");
	}
}