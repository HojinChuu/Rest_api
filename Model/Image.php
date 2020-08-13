<?php

class ImageException extends Exception { }

class Image
{
    private $_id;
    private $_title;
    private $_filename;
    private $_mimetype;
    private $_taskid;
    private $_uploadFolderLocation;

    public function __construct($id, $title, $filename, $mimetype, $taskid)
    {
        $this->setID($id);
        $this->setTitle($title);
        $this->setFilename($filename);
        $this->setMimetype($mimetype);
        $this->setTaskID($taskid);
        $this->_uploadFolderLocation = "../images/";
    }

    public function getID()
    {
        return $this->_id;
    }

    public function getTitle()
    {
        return $this->_title;
    }

    public function getFilename()
    {
        return $this->_filename;
    }

    public function getFileExtension()
    {
        $filenameParts = explode(".", $this->_filename);
        $lastArrayElement = count($filenameParts)-1;
        $fileExtension = $filenameParts[$lastArrayElement];

        return $fileExtension;
    }

    public function getMimetype()
    {
        return $this->_mimetype;
    }

    public function getTaskID()
    {
        return $this->_taskid;
    }

    public function getUploadFolderLocation()
    {
        return $this->_uploadFolderLocation;
    }

    public function getImageUrl()
    {
        $httpOrHttps = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http");
        $host = $_SERVER['HTTP_HOST'];
        $url = "/restapi/tasks/{$this->getTaskID()}/images/{$this->getID()}";

        return $httpOrHttps . "://" . $host . $url;
    }

    public function returnImageFile()
    {
        $filepath = $this->getUploadFolderLocation().$this->getTaskID().'/'.$this->getFilename();

        if (!file_exists($filepath)) {
            throw new ImageException("Image file not found");
        }
        
        header('Content-Type: '.$this->getMimetype()); // 바이너리반환 change content type 
       
        header("Content-Disposition: inline; filename={$this->getFilename()}");

        if (!readfile($filepath)) {
            http_response_code(404);
            exit();
        }
        exit();
    }

    public function setID($id)
    {
        if (($id !== null) && (!is_numeric($id) || $id <= 0 || $id > 9223372036854775807 || $this->_id !== null)) {
            throw new ImageException("Image ID Error");
        }

        $this->_id = $id;
    }

    public function setTitle($title) 
    {
        if (strlen($title) < 1 || strlen($title) > 255) {
            throw new ImageException("Image title Error");
        }

        $this->_title = $title;
    }

    public function setFilename($filename) 
    {
        if (strlen($filename) < 1 || strlen($filename) > 30 || preg_match("#^[a-zA-Z0-9_-]+(.jpg|.gif|.png)$#", $filename) != 1) {
            throw new ImageException("Image filename Error and only be .jpg .png .gif");
        }

        $this->_filename = $filename;
    }

    public function setMimetype($mimetype)
    {
        if (strlen($mimetype) < 1 || strlen($mimetype) > 255) {
            throw new ImageException("Image mimetype Error");
        }
        
        $this->_mimetype = $mimetype;
    }

    public function setTaskID($taskid) 
    {
        if (($taskid !== null) && (!is_numeric($taskid) || $taskid <= 0 || $taskid > 9223372036854775807 || $this->_taskid !== null)) {
            throw new ImageException("Image Task ID Error");
        }

        $this->_taskid = $taskid;
    }

    // image file delete
    public function deleteImageFile() 
    {
        $filepath = $this->getUploadFolderLocation().$this->getTaskID()."/".$this->getFilename();

        if (file_exists($filepath)) {
            // 실행하고 실패하면 throw
            if (!unlink($filepath)) {
                throw new ImageException("Failed to delete image file");
            }
        }
    }

    public function saveImageFile($tempFileName)
    {
        $uploadedFilePath = $this->getUploadFolderLocation().$this->getTaskID().'/'.$this->getFilename();

        if (!is_dir($this->getUploadFolderLocation().$this->getTaskID())) {
            if (!mkdir($this->getUploadFolderLocation().$this->getTaskID())) {
                throw new ImageException("Failed to create image upload folder for task");
            }
        }

        if (!file_exists($tempFileName)) {
            throw new ImageException("Failed to upload image file");
        }

        if (!move_uploaded_file($tempFileName, $uploadedFilePath)) {
            throw new ImageException("Failed to upload image file");
        }
    }

    public function renameImageFile($oldFileName, $newFileName) 
    {
        $originalFilePath = $this->getUploadFolderLocation().$this->getTaskID()."/".$oldFileName;
        $renameFilePath = $this->getUploadFolderLocation().$this->getTaskID()."/".$newFileName;

        if (!file_exists($originalFilePath)) {
            throw new ImageException("Cannot find image file");
        }

        if (!rename($originalFilePath, $renameFilePath)) {
            throw new ImageException("Failed to update the filename");
        }
    }

    public function returnImageAsArray()
    {
        $image = [];
        $image['id'] = $this->getID();
        $image['title'] = $this->getTitle();
        $image['filename'] = $this->getFilename();
        $image['mimetype'] = $this->getMimetype();
        $image['taskid'] = $this->getTaskID();
        $image['imageurl'] = $this->getImageUrl();

        return $image;
    }
}